<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Engine\FixedSchedule;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class ScanPoisHandler extends AbstractTripMessageHandler
{
    private const float LUNCH_NUDGE_DISTANCE_KM = 40.0;

    /**
     * Clustering radius in meters: POIs within this distance are grouped into a single marker.
     */
    private const float CLUSTER_RADIUS_METERS = 500.0;

    /** @var list<string> */
    private const array RESUPPLY_CATEGORIES = [
        'restaurant', 'cafe', 'bar', 'supermarket', 'convenience',
        'bakery', 'fast_food', 'marketplace', 'butcher', 'pastry',
        'deli', 'greengrocer', 'general', 'farm',
    ];

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private GeometryDistributorInterface $distributor,
        private GeoDistanceInterface $haversine,
        private RiderTimeEstimatorInterface $riderTimeEstimator,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(ScanPois $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';
        $request = $this->tripStateManager->getRequest($tripId);
        $departureHour = $request instanceof TripRequest ? $request->departureHour : 8;
        $averageSpeed = $request instanceof TripRequest ? $request->averageSpeed : 15.0;

        $this->executeWithTracking($tripId, ComputationName::POIS, function () use ($tripId, $stages, $locale, $departureHour, $averageSpeed): void {
            // Build one POI query per stage + one global cemetery query (concurrent via queryBatch)
            /** @var list<list<Coordinate>> $stageGeometries */
            $stageGeometries = array_map(
                static fn (Stage $stage): array => $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                $stages,
            );

            $allPoints = array_merge(...$stageGeometries);

            /** @var array<string, string> $queries */
            $queries = ['cemetery' => $this->queryBuilder->buildCemeteryQuery($allPoints)];
            foreach ($stages as $i => $stage) {
                $queries['poi_' . $i] = $this->queryBuilder->buildPoiQuery($stageGeometries[$i]);
            }

            $results = $this->scanner->queryBatch($queries);

            // Parse POIs per stage
            /** @var array<int, list<array{name: string, category: string, lat: float, lon: float}>> $poisByStage */
            $poisByStage = [];
            foreach ($stages as $i => $stage) {
                $poiResult = $results['poi_' . $i] ?? [];
                /** @var list<array{tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
                $elements = \is_array($poiResult['elements'] ?? null) ? $poiResult['elements'] : [];

                $poisByStage[$i] = [];
                foreach ($elements as $element) {
                    $tags = $element['tags'] ?? [];
                    $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                    $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                    if (null === $lat || null === $lon) {
                        continue;
                    }

                    $category = $tags['amenity'] ?? $tags['shop'] ?? $tags['tourism'] ?? 'unknown';
                    $name = $tags['name'] ?? $category;

                    $poisByStage[$i][] = [
                        'name' => $name,
                        'category' => $category,
                        'lat' => (float) $lat,
                        'lon' => (float) $lon,
                    ];
                }
            }

            // Parse water points (cemeteries) for supply timeline
            $cemeteryResult = $results['cemetery'] ?? [];
            /** @var list<array{tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $cemeteryElements */
            $cemeteryElements = \is_array($cemeteryResult['elements'] ?? null) ? $cemeteryResult['elements'] : [];
            $allWaterPoints = [];
            foreach ($cemeteryElements as $element) {
                $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);
                $tags = $element['tags'] ?? [];
                $name = $tags['name'] ?? null;

                if (null === $lat || null === $lon) {
                    continue;
                }

                $allWaterPoints[] = [
                    'name' => $name,
                    'lat' => (float) $lat,
                    'lon' => (float) $lon,
                ];
            }

            // Distribute water points to their nearest stage by geometry
            /** @var array<int, list<array{name: string|null, lat: float, lon: float}>> $waterByStage */
            $waterByStage = $this->distributor->distributeByGeometry($allWaterPoints, $stages);

            foreach ($stages as $i => $stage) {
                $pois = [];
                foreach ($poisByStage[$i] ?? [] as $raw) {
                    $poi = new PointOfInterest(
                        name: $raw['name'],
                        category: $raw['category'],
                        lat: $raw['lat'],
                        lon: $raw['lon'],
                    );

                    $stage->addPoi($poi);
                    $pois[] = ['name' => $poi->name, 'category' => $poi->category];
                }

                // Lunch nudge: flag long stages with no food POIs
                $alerts = [];
                if ($stage->distance >= self::LUNCH_NUDGE_DISTANCE_KM && !$this->hasResupplyPoi($stage)) {
                    $alert = new Alert(
                        type: AlertType::NUDGE,
                        message: $this->translator->trans('alert.lunch.nudge', [], 'alerts', $locale),
                        lat: $stage->startPoint->lat,
                        lon: $stage->startPoint->lon,
                    );
                    $stage->addAlert($alert);
                    $alerts[] = ['type' => 'nudge', 'message' => $alert->message, 'lat' => $alert->lat, 'lon' => $alert->lon];
                }

                // Resupply timing warning: warn when all resupply POIs on this stage
                // would be closed at the estimated rider passage time
                if ($this->hasResupplyPoi($stage) && !$this->hasAnyOpenResupplyPoi($stage, $departureHour, $averageSpeed)) {
                    $alert = new Alert(
                        type: AlertType::WARNING,
                        message: $this->translator->trans(
                            'alert.resupply.timing_warning',
                            ['%stage%' => $stage->dayNumber],
                            'alerts',
                            $locale,
                        ),
                        lat: $stage->startPoint->lat,
                        lon: $stage->startPoint->lon,
                    );
                    $stage->addAlert($alert);
                    $alerts[] = ['type' => 'warning', 'message' => $alert->message, 'lat' => $alert->lat, 'lon' => $alert->lon];
                }

                $payload = [
                    'stageIndex' => $i,
                    'pois' => $pois,
                ];

                if ([] !== $alerts) {
                    $payload['alerts'] = $alerts;
                }

                $this->publisher->publish($tripId, MercureEventType::POIS_SCANNED, $payload);

                // Compute and publish supply timeline for this stage
                $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
                $cumulativeDistances = $this->buildCumulativeDistances($geometry);

                $foodPoisRaw = array_filter(
                    $poisByStage[$i] ?? [],
                    fn (array $p): bool => \in_array($p['category'], self::RESUPPLY_CATEGORIES, true),
                );

                $foodPoisWithDistance = $this->computeDistancesForSupply($geometry, $cumulativeDistances, array_values($foodPoisRaw));
                $waterPointsWithDistance = $this->computeDistancesForSupply($geometry, $cumulativeDistances, array_map(
                    static fn (array $w): array => ['name' => $w['name'] ?? null, 'category' => 'water', 'lat' => $w['lat'], 'lon' => $w['lon']],
                    $waterByStage[$i] ?? [],
                ));

                $clusteredMarkers = $this->clusterSupplyMarkers($foodPoisWithDistance, $waterPointsWithDistance);

                if ([] !== $clusteredMarkers) {
                    $this->publisher->publish($tripId, MercureEventType::SUPPLY_TIMELINE, [
                        'stageIndex' => $i,
                        'markers' => $clusteredMarkers,
                    ]);
                }
            }

            $this->tripStateManager->storeStages($tripId, $stages);
        });
    }

    private function hasResupplyPoi(Stage $stage): bool
    {
        return array_any($stage->pois, fn ($poi): bool => \in_array($poi->category, self::RESUPPLY_CATEGORIES, true));
    }

    /**
     * Returns true when at least one resupply POI on the stage is open
     * at the estimated rider passage time.
     */
    private function hasAnyOpenResupplyPoi(Stage $stage, int $departureHour, float $averageSpeed): bool
    {
        $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
        $cumulativeDistances = $this->buildCumulativeDistances($geometry);
        $totalDistance = $stage->distance;

        foreach ($stage->pois as $poi) {
            if (!\in_array($poi->category, self::RESUPPLY_CATEGORIES, true)) {
                continue;
            }

            $nearestIndex = $this->findNearestGeometryIndex($geometry, $poi->lat, $poi->lon);
            $distanceFromStart = $cumulativeDistances[$nearestIndex];
            $estimatedTime = $this->riderTimeEstimator->estimateTimeAtDistance($distanceFromStart, $totalDistance, $departureHour, $averageSpeed, $stage->elevation);
            $schedule = $this->resolveSchedule($poi->category);

            if ($schedule->isOpenAt($estimatedTime)) {
                return true;
            }
        }

        return false;
    }

    private function resolveSchedule(string $category): FixedSchedule
    {
        return match (true) {
            \in_array($category, ['supermarket', 'convenience', 'general', 'farm', 'greengrocer', 'butcher', 'deli'], true) => FixedSchedule::supermarket(),
            \in_array($category, ['restaurant', 'cafe', 'bar', 'fast_food'], true) => FixedSchedule::restaurant(),
            \in_array($category, ['bakery', 'pastry'], true) => FixedSchedule::bakery(),
            default => FixedSchedule::noFilter(),
        };
    }

    /**
     * Computes the distance from stage start for each supply point and returns
     * them sorted by distance.
     *
     * @param list<Coordinate>                                                         $geometry
     * @param list<float>                                                              $cumulativeDistances
     * @param list<array{name: string|null, category: string, lat: float, lon: float}> $items
     *
     * @return list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}>
     */
    private function computeDistancesForSupply(array $geometry, array $cumulativeDistances, array $items): array
    {
        if ([] === $items) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            $nearestIndex = $this->findNearestGeometryIndex($geometry, $item['lat'], $item['lon']);
            $result[] = [
                'name' => $item['name'],
                'category' => $item['category'],
                'lat' => $item['lat'],
                'lon' => $item['lon'],
                'distanceFromStart' => round($cumulativeDistances[$nearestIndex], 1),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $a['distanceFromStart'] <=> $b['distanceFromStart']);

        return $result;
    }

    /**
     * Clusters food and water POIs within CLUSTER_RADIUS_METERS into single markers.
     *
     * Items are clustered by comparing each point to the first item of the nearest open cluster
     * (anchor-based): a new item joins a cluster when its distance to that cluster's anchor is
     * within CLUSTER_RADIUS_METERS. The marker type is:
     * - 'water'  when only water points are present
     * - 'food'   when only food/shop POIs are present
     * - 'both'   when both are present in the same zone
     *
     * @param list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $foodPois
     * @param list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $waterPoints
     *
     * @return list<array{
     *     type: 'water'|'food'|'both',
     *     distanceFromStart: float,
     *     lat: float,
     *     lon: float,
     *     water: list<array{name: string|null, lat: float, lon: float, distanceFromStart: float}>,
     *     food: list<array{name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}>,
     * }>
     */
    private function clusterSupplyMarkers(array $foodPois, array $waterPoints): array
    {
        // Merge all items with their type for clustering
        /** @var list<array{itemType: 'food'|'water', name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}> $all */
        $all = [];

        foreach ($foodPois as $p) {
            $all[] = array_merge($p, ['itemType' => 'food']);
        }

        foreach ($waterPoints as $w) {
            $all[] = array_merge($w, ['itemType' => 'water']);
        }

        if ([] === $all) {
            return [];
        }

        // Sort by distance from start
        usort($all, static fn (array $a, array $b): int => $a['distanceFromStart'] <=> $b['distanceFromStart']);

        // Greedy clustering: group items within CLUSTER_RADIUS_METERS of each other
        /** @var list<list<array{itemType: 'food'|'water', name: string|null, category: string, lat: float, lon: float, distanceFromStart: float}>> $clusters */
        $clusters = [];

        foreach ($all as $item) {
            $placed = false;

            foreach ($clusters as $k => $cluster) {
                // Use the first item in the cluster as the cluster centroid for distance check
                $centroid = $cluster[0];
                $dist = $this->haversine->inMeters($centroid['lat'], $centroid['lon'], $item['lat'], $item['lon']);

                if ($dist <= self::CLUSTER_RADIUS_METERS) {
                    $clusters[$k][] = $item;
                    $placed = true;
                    break;
                }
            }

            if (!$placed) {
                $clusters[] = [$item];
            }
        }

        // Build output markers from clusters
        $markers = [];

        foreach ($clusters as $cluster) {
            $hasFood = false;
            $hasWater = false;
            $foodItems = [];
            $waterItems = [];

            foreach ($cluster as $item) {
                if ('food' === $item['itemType']) {
                    $hasFood = true;
                    $foodItems[] = [
                        'name' => $item['name'],
                        'category' => $item['category'],
                        'lat' => $item['lat'],
                        'lon' => $item['lon'],
                        'distanceFromStart' => $item['distanceFromStart'],
                    ];
                } else {
                    $hasWater = true;
                    $waterItems[] = [
                        'name' => $item['name'],
                        'lat' => $item['lat'],
                        'lon' => $item['lon'],
                        'distanceFromStart' => $item['distanceFromStart'],
                    ];
                }
            }

            $type = match (true) {
                $hasFood && $hasWater => 'both',
                $hasFood => 'food',
                default => 'water',
            };

            // Representative position: first item in cluster (sorted by distance)
            $first = $cluster[0];

            $markers[] = [
                'type' => $type,
                'distanceFromStart' => $first['distanceFromStart'],
                'lat' => $first['lat'],
                'lon' => $first['lon'],
                'water' => $waterItems,
                'food' => $foodItems,
            ];
        }

        return $markers;
    }

    /**
     * Builds an array of cumulative distances (in km) along the geometry.
     *
     * @param list<Coordinate> $geometry
     *
     * @return list<float>
     */
    private function buildCumulativeDistances(array $geometry): array
    {
        $cumulative = [0.0];

        for ($i = 1, $count = \count($geometry); $i < $count; ++$i) {
            $prev = $geometry[$i - 1];
            $curr = $geometry[$i];
            $cumulative[] = $cumulative[$i - 1] + $this->haversine->inKilometers($prev->lat, $prev->lon, $curr->lat, $curr->lon);
        }

        return $cumulative;
    }

    /**
     * Finds the index of the closest geometry point to the given coordinates.
     *
     * @param list<Coordinate> $geometry
     */
    private function findNearestGeometryIndex(array $geometry, float $lat, float $lon): int
    {
        $minDist = PHP_FLOAT_MAX;
        $nearest = 0;

        foreach ($geometry as $i => $point) {
            $dist = $this->haversine->inMeters($point->lat, $point->lon, $lat, $lon);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $i;
            }
        }

        return $nearest;
    }
}
