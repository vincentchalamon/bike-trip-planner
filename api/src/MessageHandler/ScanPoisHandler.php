<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;
use App\ApiResource\TripRequest;
use App\Engine\RiderTimeEstimatorInterface;
use App\Entity\User;
use App\Enum\AlertGroup;
use App\Geo\GeometryDistributorInterface;
use App\Mapper\StageArrayMapper;
use App\Mercure\MercureEventType;
use App\Message\ScanPois;
use App\Osm\WaterPointRepositoryInterface;
use App\Poi\PoiLabelResolver;
use App\Poi\PoiSourceRegistry;
use App\Poi\ResupplyAlertRules;
use App\Poi\ResupplyBuilder;
use App\Poi\SupplyTimelineBuilder;
use App\Repository\TransientTripPointsStoreInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ScanPoisHandler extends AbstractTripMessageHandler
{
    /** The clock time (decimal hours) the resupply suggestions are anchored on: 12:30. */
    private const float LUNCH_HOUR = 12.5;

    /** Corridor half-width (m) for the local-first POI/water reads (ADR-040), matching the former Overpass "around" radius. */
    private const int CORRIDOR_RADIUS_METERS = 2000;

    public function __construct(
        TripHandlerContext $context,
        private TransientTripPointsStoreInterface $points,
        private PoiSourceRegistry $poiSourceRegistry,
        private WaterPointRepositoryInterface $waterPointRepository,
        private GeometryDistributorInterface $distributor,
        private SupplyTimelineBuilder $supplyTimelineBuilder,
        private ResupplyBuilder $resupplyBuilder,
        private PoiLabelResolver $poiLabels,
        private RiderTimeEstimatorInterface $riderTimeEstimator,
        private StageArrayMapper $stageMapper,
        private ResupplyAlertRules $resupplyRules,
    ) {
        parent::__construct($context);
    }

    public function __invoke(ScanPois $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripRequestRepository->getLocale($tripId) ?? User::FALLBACK_LOCALE;
        $request = $this->tripRequestRepository->getRequest($tripId);
        $departureHour = $request instanceof TripRequest ? $request->departureHour : TripRequest::DEFAULT_DEPARTURE_HOUR;
        $averageSpeed = $request instanceof TripRequest ? $request->averageSpeed : TripRequest::DEFAULT_AVERAGE_SPEED;
        // Needed to evaluate weekday-dependent opening_hours rules ("Mo-Sa 08:00-19:00").
        $startDate = $request instanceof TripRequest ? $request->startDate : null;

        $this->executeWithTracking($message, function () use ($tripId, $stages, $locale, $departureHour, $averageSpeed, $startDate): void {
            // Decode the route corridor from the decimated points (fallback: stage geometry).
            $route = $this->routeCorridor($this->points, $tripId, $stages);

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
                // Full corridor set, kept local: the alert checks read it before it
                // is curated to the resupply suggestions the client receives.
                $fullPois = [];
                foreach ($poisByStage[$i] ?? [] as $raw) {
                    $fullPois[] = new PointOfInterest(
                        name: $raw['name'],
                        category: $raw['category'],
                        lat: $raw['lat'],
                        lon: $raw['lon'],
                        osmType: $raw['osmType'] ?? null,
                        osmId: $raw['osmId'] ?? null,
                        openingHours: $raw['openingHours'],
                        website: $raw['website'],
                    );
                }

                // Position food + water along the route, once: the alert rules, the resupply
                // curation and the supply timeline all read the same distances.
                $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
                $cumulativeDistances = $this->supplyTimelineBuilder->buildCumulativeDistances($geometry);

                $stageDate = $startDate instanceof \DateTimeImmutable ? $stage->dateFrom($startDate) : null;
                $alerts = $this->resupplyRules->alertsFor(
                    $stage,
                    $fullPois,
                    $geometry,
                    $cumulativeDistances,
                    $departureHour,
                    $averageSpeed,
                    null !== $stageDate ? (int) $stageDate->format('N') : null,
                );

                $foodPoisWithDistance = $this->supplyTimelineBuilder->computeDistancesForSupply($geometry, $cumulativeDistances, array_values(array_filter(
                    $poisByStage[$i] ?? [],
                    static fn (array $p): bool => ResupplyAlertRules::isResupply($p['category']),
                )));
                $waterPointsWithDistance = $this->supplyTimelineBuilder->computeDistancesForSupply($geometry, $cumulativeDistances, array_map(
                    static fn (array $w): array => ['name' => $w['name'], 'category' => 'water', 'lat' => $w['lat'], 'lon' => $w['lon']],
                    $waterByStage[$i] ?? [],
                ));

                // Curate the persisted POIs to <=6 resupply suggestions (#1099): the
                // raw corridor set (thousands per stage) is a computation input, never
                // a client payload — it blocked the mobile trip-open parse.
                $lunchKm = $this->riderTimeEstimator->distanceAtHour(self::LUNCH_HOUR, $stage->distance, $departureHour, $averageSpeed, $stage->elevation);
                $stage->resupply = $this->resupplyBuilder->select(
                    $foodPoisWithDistance,
                    $waterPointsWithDistance,
                    $lunchKm,
                    $stage->distance,
                    $this->poiLabels->displayName('water_point', $locale),
                );

                // Same array to both consumers (ADR-068), the empty one included: a rerun that
                // finds nothing has to clear the previous alerts on a live client too, or the
                // database and the open page disagree until a reload.
                $this->stageStore->updateStageAlertsForGroup($tripId, $stage->id, AlertGroup::POIS, $alerts);
                $this->publisher->publish($tripId, MercureEventType::POIS_SCANNED, [
                    'stageId' => $stage->id,
                    'resupply' => $this->stageMapper->resupplyForClient($stage->resupply),
                    'alerts' => $this->alertRenderer->render($alerts, $stage->dayNumber, $this->tripRequestRepository->getLocale($tripId) ?? User::FALLBACK_LOCALE),
                ]);

                $clusteredMarkers = $this->supplyTimelineBuilder->clusterSupplyMarkers($foodPoisWithDistance, $waterPointsWithDistance);

                // Published and persisted unconditionally, empty list included: the timeline is
                // recomputed wholesale, so an empty result has to clear a previous one on both
                // sides rather than leave it standing.
                $this->stageStore->updateStageSupplyTimeline($tripId, $stage->id, $clusteredMarkers);
                $this->publisher->publish($tripId, MercureEventType::SUPPLY_TIMELINE, [
                    'stageId' => $stage->id,
                    'markers' => $clusteredMarkers,
                ]);
            }

            // Persist the curated resupply with an atomic per-column UPDATE per stage
            // (recette #649). The lunch/resupply alerts added above are delivered live
            // via Mercure (above); AnalyzeTerrain owns the persisted alerts column.
            foreach ($stages as $stage) {
                $this->stageStore->updateStageResupply($tripId, $stage->id, $stage->resupply ?? new Resupply());
            }
        });
    }
}
