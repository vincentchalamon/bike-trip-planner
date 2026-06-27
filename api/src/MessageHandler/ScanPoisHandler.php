<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\FixedSchedule;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanPois;
use App\Osm\WaterPointRepositoryInterface;
use App\Poi\PoiSourceRegistry;
use App\Poi\SupplyTimelineBuilder;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class ScanPoisHandler extends AbstractTripMessageHandler
{
    private const float LUNCH_NUDGE_DISTANCE_KM = 40.0;

    /** Corridor half-width (m) for the local-first POI/water reads (ADR-040), matching the former Overpass "around" radius. */
    private const int CORRIDOR_RADIUS_METERS = 2000;

    /** @var list<string> */
    private const array RESUPPLY_CATEGORIES = [
        'restaurant', 'cafe', 'bar', 'supermarket', 'convenience',
        'bakery', 'fast_food', 'marketplace', 'butcher', 'pastry',
        'deli', 'greengrocer', 'general', 'farm', 'fuel',
    ];

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private PoiSourceRegistry $poiSourceRegistry,
        private WaterPointRepositoryInterface $waterPointRepository,
        private GeometryDistributorInterface $distributor,
        private SupplyTimelineBuilder $supplyTimelineBuilder,
        private RiderTimeEstimatorInterface $riderTimeEstimator,
        private TranslatorInterface $translator,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
    }

    public function __invoke(ScanPois $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';
        $request = $this->tripStateManager->getRequest($tripId);
        $departureHour = $request instanceof TripRequest ? $request->departureHour : 8;
        $averageSpeed = $request instanceof TripRequest ? $request->averageSpeed : 15.0;

        $this->executeWithTracking($tripId, ComputationName::POIS, function () use ($tripId, $stages, $locale, $departureHour, $averageSpeed): void {
            // Decode the route corridor from the decimated points (fallback: stage geometry).
            $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);
            $allPoints = null !== $decimatedData
                ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $decimatedData)
                : array_merge(...array_map(
                    static fn (Stage $stage): array => $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                    $stages,
                ));

            $route = array_map(static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon], $allPoints);

            // Read POIs and real drinking-water points from the local-first index along the
            // route corridor (ADR-040), then distribute them to stages by geometry. POIs come
            // from every source (OSM + DataTourisme food), merged by proximity + name. The local
            // index returns deterministic results, so a long stage with genuinely no resupply
            // POI is no longer indistinguishable from an Overpass failure (lunch-nudge fix).
            $allPois = [];
            foreach ($this->poiSourceRegistry->fetchAllInCorridor($route, self::CORRIDOR_RADIUS_METERS) as $poi) {
                $allPois[] = [
                    'name' => $poi['name'],
                    'category' => $poi['category'],
                    'lat' => $poi['lat'],
                    'lon' => $poi['lon'],
                ];
            }

            /** @var array<int, list<array{name: string, category: string, lat: float, lon: float}>> $poisByStage */
            $poisByStage = $this->distributor->distributeByGeometry($allPois, $stages);

            $allWaterPoints = $this->waterPointRepository->findInCorridor($route, self::CORRIDOR_RADIUS_METERS);

            /** @var array<int, list<array{name: string|null, category: string, lat: float, lon: float}>> $waterByStage */
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
                $cumulativeDistances = $this->supplyTimelineBuilder->buildCumulativeDistances($geometry);

                $foodPoisRaw = array_filter(
                    $poisByStage[$i] ?? [],
                    fn (array $p): bool => \in_array($p['category'], self::RESUPPLY_CATEGORIES, true),
                );

                $foodPoisWithDistance = $this->supplyTimelineBuilder->computeDistancesForSupply($geometry, $cumulativeDistances, array_values($foodPoisRaw));
                $waterPointsWithDistance = $this->supplyTimelineBuilder->computeDistancesForSupply($geometry, $cumulativeDistances, array_map(
                    static fn (array $w): array => ['name' => $w['name'] ?? null, 'category' => 'water', 'lat' => $w['lat'], 'lon' => $w['lon']],
                    $waterByStage[$i] ?? [],
                ));

                $clusteredMarkers = $this->supplyTimelineBuilder->clusterSupplyMarkers($foodPoisWithDistance, $waterPointsWithDistance);

                if ([] !== $clusteredMarkers) {
                    $this->publisher->publish($tripId, MercureEventType::SUPPLY_TIMELINE, [
                        'stageIndex' => $i,
                        'markers' => $clusteredMarkers,
                    ]);
                }
            }

            // Persist POIs with an atomic per-column UPDATE per stage (recette #649).
            // The lunch/resupply alerts added above are delivered live via Mercure
            // (above); AnalyzeTerrain owns the persisted alerts column.
            foreach ($stages as $stage) {
                $this->tripStateManager->updateStagePois($tripId, $stage->dayNumber, array_values($stage->pois));
            }
        }, $generation);
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
        $cumulativeDistances = $this->supplyTimelineBuilder->buildCumulativeDistances($geometry);
        $totalDistance = $stage->distance;

        foreach ($stage->pois as $poi) {
            if (!\in_array($poi->category, self::RESUPPLY_CATEGORIES, true)) {
                continue;
            }

            $nearestIndex = $this->supplyTimelineBuilder->findNearestGeometryIndex($geometry, $poi->lat, $poi->lon);
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
}
