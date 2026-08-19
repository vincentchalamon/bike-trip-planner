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
use App\Engine\OpeningHours;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\AlertCode;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanPois;
use App\Osm\WaterPointRepositoryInterface;
use App\Poi\PoiLabelResolver;
use App\Poi\PoiSourceRegistry;
use App\Poi\ResupplyBuilder;
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
        private ResupplyBuilder $resupplyBuilder,
        private PoiLabelResolver $poiLabels,
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
        // Needed to evaluate weekday-dependent opening_hours rules ("Mo-Sa 08:00-19:00").
        $startDate = $request instanceof TripRequest ? $request->startDate : null;

        $this->executeWithTracking($tripId, ComputationName::POIS, function () use ($tripId, $stages, $locale, $departureHour, $averageSpeed, $startDate): void {
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
            // A POI the index has no name for keeps its slot in the scan (the
            // coordinates alone are actionable and the resupply count drives the
            // lunch nudge), but it gets a localised category label rather than the
            // raw OSM slug.
            $allPois = [];
            foreach ($this->poiSourceRegistry->fetchAllInCorridor($route, self::CORRIDOR_RADIUS_METERS) as $poi) {
                $allPois[] = [
                    'name' => $poi['name'] ?? $this->poiLabels->displayName($poi['category'], $locale),
                    'category' => $poi['category'],
                    'lat' => $poi['lat'],
                    'lon' => $poi['lon'],
                    'openingHours' => $poi['openingHours'],
                    'website' => $poi['website'],
                ];
            }

            /** @var array<int, list<array{name: string, category: string, lat: float, lon: float, openingHours: string|null, website: string|null}>> $poisByStage */
            $poisByStage = $this->distributor->distributeByGeometry($allPois, $stages);

            $allWaterPoints = $this->waterPointRepository->findInCorridor($route, self::CORRIDOR_RADIUS_METERS);

            /** @var array<int, list<array{name: string|null, category: string, lat: float, lon: float}>> $waterByStage */
            $waterByStage = $this->distributor->distributeByGeometry($allWaterPoints, $stages);

            foreach ($stages as $i => $stage) {
                // Build the full corridor set on the stage: the alert checks below
                // read it before it is curated to the resupply suggestions.
                foreach ($poisByStage[$i] ?? [] as $raw) {
                    $stage->addPoi(new PointOfInterest(
                        name: $raw['name'],
                        category: $raw['category'],
                        lat: $raw['lat'],
                        lon: $raw['lon'],
                        osmType: $raw['osmType'] ?? null,
                        osmId: $raw['osmId'] ?? null,
                        openingHours: $raw['openingHours'],
                        website: $raw['website'],
                    ));
                }

                // Lunch nudge: flag long stages with no food POIs.
                // Both alerts below are about passing through while riding, so a rest day
                // is skipped — its POIs are still scanned and published (useful on the spot).
                $alerts = [];
                if (!$stage->isRestDay && $stage->distance >= self::LUNCH_NUDGE_DISTANCE_KM && !$this->hasResupplyPoi($stage)) {
                    $alert = new Alert(
                        code: AlertCode::RESUPPLY_NONE_ON_STAGE,
                        type: AlertType::NUDGE,
                        message: $this->translator->trans('alert.lunch.nudge', [], 'alerts', $locale),
                        lat: $stage->startPoint->lat,
                        lon: $stage->startPoint->lon,
                    );
                    $stage->addAlert($alert);
                    $alerts[] = ['code' => AlertCode::RESUPPLY_NONE_ON_STAGE->value, 'type' => 'nudge', 'message' => $alert->message, 'lat' => $alert->lat, 'lon' => $alert->lon];
                }

                // Resupply timing warning: warn when every resupply POI on this stage is
                // *known* to be closed at the estimated rider passage time.
                $stageDate = $startDate?->modify(\sprintf('+%d days', $i));

                if (!$stage->isRestDay && $this->allResupplyPoisAreClosed($stage, $departureHour, $averageSpeed, null !== $stageDate ? (int) $stageDate->format('N') : null)) {
                    $alert = new Alert(
                        code: AlertCode::RESUPPLY_CLOSED_AT_PASSAGE,
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
                    $alerts[] = ['code' => AlertCode::RESUPPLY_CLOSED_AT_PASSAGE->value, 'type' => 'warning', 'message' => $alert->message, 'lat' => $alert->lat, 'lon' => $alert->lon];
                }

                // Position food + water along the route (shared by the resupply
                // curation and the supply timeline).
                $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
                $cumulativeDistances = $this->supplyTimelineBuilder->buildCumulativeDistances($geometry);

                $foodPoisWithDistance = $this->supplyTimelineBuilder->computeDistancesForSupply($geometry, $cumulativeDistances, array_values(array_filter(
                    $poisByStage[$i] ?? [],
                    fn (array $p): bool => \in_array($p['category'], self::RESUPPLY_CATEGORIES, true),
                )));
                $waterPointsWithDistance = $this->supplyTimelineBuilder->computeDistancesForSupply($geometry, $cumulativeDistances, array_map(
                    static fn (array $w): array => ['name' => $w['name'], 'category' => 'water', 'lat' => $w['lat'], 'lon' => $w['lon']],
                    $waterByStage[$i] ?? [],
                ));

                // Curate the persisted POIs to <=6 resupply suggestions (#1099): the
                // raw corridor set (thousands per stage) is a computation input, never
                // a client payload — it blocked the mobile trip-open parse.
                $lunchKm = $this->estimateLunchDistanceKm($stage->distance, $departureHour, $averageSpeed, $stage->elevation);
                $stage->pois = $this->resupplyBuilder->select(
                    $foodPoisWithDistance,
                    $waterPointsWithDistance,
                    $lunchKm,
                    $stage->distance,
                    $this->poiLabels->displayName('water', $locale),
                );

                $payload = [
                    'stageIndex' => $i,
                    'pois' => array_map(
                        static fn (PointOfInterest $p): array => [
                            'name' => $p->name,
                            'category' => $p->category,
                            'lat' => $p->lat,
                            'lon' => $p->lon,
                            'distanceFromStart' => $p->distanceFromStart,
                        ],
                        $stage->pois,
                    ),
                ];

                if ([] !== $alerts) {
                    $payload['alerts'] = $alerts;
                }

                $this->publisher->publish($tripId, MercureEventType::POIS_SCANNED, $payload);

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
        return array_any($stage->pois, fn (PointOfInterest $poi): bool => \in_array($poi->category, self::RESUPPLY_CATEGORIES, true));
    }

    /**
     * Distance marker (km) at which the rider reaches the lunch hour, used to
     * anchor the resupply suggestions. Binary search over the (monotone in
     * distance) passage-time model; clamps to the stage ends when lunch falls
     * before departure or after arrival.
     */
    private function estimateLunchDistanceKm(float $totalKm, int $departureHour, float $averageSpeed, float $elevation): float
    {
        if ($totalKm <= 0.0) {
            return 0.0;
        }

        $lunchHour = 12.5;
        $lo = 0.0;
        $hi = $totalKm;
        for ($k = 0; $k < 24; ++$k) {
            $mid = ($lo + $hi) / 2;
            if ($this->riderTimeEstimator->estimateTimeAtDistance($mid, $totalKm, $departureHour, $averageSpeed, $elevation) < $lunchHour) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return ($lo + $hi) / 2;
    }

    /**
     * Returns true only when every resupply POI on the stage is *known* to be closed
     * at the estimated rider passage time.
     *
     * A single POI that is open — or whose hours cannot be established — makes the
     * stage inconclusive and suppresses the warning: it used to be raised from the
     * category-typical slots alone, i.e. from schedules nobody had checked (#875).
     *
     * @param int|null $isoWeekday 1 (Monday) to 7 (Sunday), null when the trip has no start date
     */
    private function allResupplyPoisAreClosed(Stage $stage, int $departureHour, float $averageSpeed, ?int $isoWeekday): bool
    {
        $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
        $cumulativeDistances = $this->supplyTimelineBuilder->buildCumulativeDistances($geometry);
        $totalDistance = $stage->distance;
        $closed = 0;

        foreach ($stage->pois as $poi) {
            if (!\in_array($poi->category, self::RESUPPLY_CATEGORIES, true)) {
                continue;
            }

            $nearestIndex = $this->supplyTimelineBuilder->findNearestGeometryIndex($geometry, $poi->lat, $poi->lon);
            $distanceFromStart = $cumulativeDistances[$nearestIndex];
            $estimatedTime = $this->riderTimeEstimator->estimateTimeAtDistance($distanceFromStart, $totalDistance, $departureHour, $averageSpeed, $stage->elevation);

            if (false !== $this->isOpenAt($poi, $estimatedTime, $isoWeekday)) {
                return false;
            }

            ++$closed;
        }

        return $closed > 0;
    }

    /**
     * Tri-state openness of a POI: true = open, false = closed, null = unknown.
     *
     * The real OSM `opening_hours` wins whenever it is present and understood.
     * Without it, the category-typical {@see FixedSchedule} is only allowed to
     * answer "probably open" — never "closed", which would put an invented
     * schedule behind a user-facing warning.
     */
    private function isOpenAt(PointOfInterest $poi, float $decimalHour, ?int $isoWeekday): ?bool
    {
        if (null !== $poi->openingHours) {
            return OpeningHours::parse($poi->openingHours)?->isOpenAt($decimalHour, $isoWeekday);
        }

        return FixedSchedule::forCategory($poi->category)->isOpenAt($decimalHour) ? true : null;
    }
}
