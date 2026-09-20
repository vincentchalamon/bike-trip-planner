<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertRenderer;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\CulturalPoiSource\CulturalPoiSourceRegistry;
use App\Enum\AlertCode;
use App\Enum\AlertParameterFormat;
use App\Enum\AlertGroup;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckCulturalPois;
use App\Poi\PoiLabelResolver;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Detects cultural POIs (museums, monuments, castles, churches, viewpoints)
 * within 500 m of each stage route and emits SUGGESTION alerts.
 *
 * Each alert carries the POI coordinates so the frontend can display an
 * "add to itinerary" button that triggers route recalculation via
 * RecalculateRouteSegment (ADR-017).
 *
 * POIs are fetched from all enabled sources via CulturalPoiSourceRegistry
 * (OSM via Overpass, DataTourisme when configured).
 */
#[AsMessageHandler]
final readonly class CheckCulturalPoisHandler extends AbstractTripMessageHandler
{
    /** Query radius around each route point, in metres. */
    private const int CULTURAL_POI_RADIUS_METERS = 500;

    /**
     * Maximum number of POI suggestions per stage to avoid overwhelming the UI.
     */
    private const int MAX_SUGGESTIONS_PER_STAGE = 3;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private CulturalPoiSourceRegistry $registry,
        private GeometryDistributorInterface $distributor,
        private GeoDistanceInterface $haversine,
        private PoiLabelResolver $poiLabels,
        MessageBusInterface $messageBus,
        AlertRenderer $alertRenderer,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus, $alertRenderer);
    }

    public function __invoke(CheckCulturalPois $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::CULTURAL_POIS, function () use ($tripId, $stages, $locale): void {
            // Collect geometries for non-rest-day stages
            /** @var list<list<array{lat: float, lon: float}>> $stageGeometries */
            $stageGeometries = [];
            /** @var list<int> $activeStageIndices */
            $activeStageIndices = [];
            /** @var list<Stage> $activeStages */
            $activeStages = [];
            foreach ($stages as $i => $stage) {
                if ($stage->isRestDay) {
                    continue;
                }

                $activeStageIndices[] = $i;
                $activeStages[] = $stage;
                $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
                $stageGeometries[] = array_map(
                    static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon],
                    $geometry,
                );
            }

            if ([] === $stageGeometries) {
                // Nothing found is a result, not an absence of one: the group is cleared so a
                // previous run's alerts do not survive as stale.
                $this->tripStateManager->updateTripAlertsForGroup($tripId, AlertGroup::CULTURAL_POI, []);
                $this->publisher->publish($tripId, MercureEventType::CULTURAL_POI_ALERTS, [
                    'alerts' => [],
                ]);

                return;
            }

            // Fetch all POIs from all enabled sources. Wikidata enrichment
            // (image, description, opening hours, Wikipedia URL) is baked into the
            // local index at provision time (ADR-041), so the rows arrive enriched.
            $allCulturalPois = $this->registry->fetchAllForStages($stageGeometries, self::CULTURAL_POI_RADIUS_METERS);

            // Distribute POIs to the nearest active stage via geometry
            $poisByActiveStage = $this->distributor->distributeByGeometry($allCulturalPois, $activeStages);

            $alerts = [];
            foreach ($activeStages as $activeIdx => $stage) {
                $originalIndex = $activeStageIndices[$activeIdx];
                $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];

                $stagePois = [];
                foreach ($poisByActiveStage[$activeIdx] ?? [] as $poi) {
                    $distanceFromRoute = $this->findMinDistanceToRoute($geometry, $poi['lat'], $poi['lon']);

                    $stagePois[] = array_merge($poi, ['distanceFromRoute' => $distanceFromRoute]);
                }

                // Sort by proximity and keep only the closest N suggestions
                usort($stagePois, static fn (array $a, array $b): int => $a['distanceFromRoute'] <=> $b['distanceFromRoute']);
                $stagePois = \array_slice($stagePois, 0, self::MAX_SUGGESTIONS_PER_STAGE);

                foreach ($stagePois as $poi) {
                    // A POI the index has no name for is still worth suggesting,
                    // since the coordinates and the "add to itinerary" action are
                    // what the rider acts on, but it is announced by its localised
                    // category instead of repeating a raw slug as both name and type.
                    $rawName = $poi['name'];
                    $name = $rawName ?? $this->poiLabels->displayName($poi['type'], $locale);
                    // The unnamed phrasing announces the POI by its category, so it needs no
                    // `%name%`; both variants are the same rule, hence the same code.
                    $parameters = ['%type%' => $poi['type'], '%distance%' => $poi['distanceFromRoute']];
                    if (null !== $rawName) {
                        $parameters['%name%'] = $rawName;
                    }

                    $alert = [
                        'stageId' => $stage->id,
                        'dayNumber' => $stage->dayNumber,
                        'code' => AlertCode::CULTURAL_POI_SUGGESTION->value,
                        'type' => AlertType::NUDGE->value,
                        'messageKey' => null === $rawName ? 'alert.cultural_poi.suggestion_unnamed' : 'alert.cultural_poi.suggestion',
                        'parameters' => $parameters,
                        'parameterFormats' => ['%type%' => AlertParameterFormat::POI_LABEL->value],
                        'lat' => $poi['lat'],
                        'lon' => $poi['lon'],
                        'poiName' => $name,
                        'poiType' => $poi['type'],
                        'poiLat' => $poi['lat'],
                        'poiLon' => $poi['lon'],
                        'distanceFromRoute' => $poi['distanceFromRoute'],
                    ];

                    if (null !== ($poi['openingHours'] ?? null)) {
                        $alert['openingHours'] = $poi['openingHours'];
                    }

                    if (null !== ($poi['website'] ?? null)) {
                        $alert['website'] = $poi['website'];
                    }

                    if (null !== ($poi['estimatedPrice'] ?? null)) {
                        $alert['estimatedPrice'] = $poi['estimatedPrice'];
                    }

                    if (null !== ($poi['description'] ?? null)) {
                        $alert['description'] = $poi['description'];
                    }

                    if (null !== ($poi['wikidataId'] ?? null)) {
                        $alert['wikidataId'] = $poi['wikidataId'];
                    }

                    if (null !== $poi['source']) {
                        $alert['source'] = $poi['source'];
                    }

                    if (null !== ($poi['imageUrl'] ?? null)) {
                        $alert['imageUrl'] = $poi['imageUrl'];
                    }

                    if (null !== ($poi['wikipediaUrl'] ?? null)) {
                        $alert['wikipediaUrl'] = $poi['wikipediaUrl'];
                    }

                    // Only an OSM entry has one; a curated DataTourisme POI does not.
                    if (null !== ($poi['osmType'] ?? null) && null !== ($poi['osmId'] ?? null)) {
                        $alert['osmType'] = $poi['osmType'];
                        $alert['osmId'] = $poi['osmId'];
                    }

                    $alerts[] = $alert;
                }
            }

            // Same array to the database and to the wire (ADR-068): grouped by the stage
            // it addresses, without `stageId`/`dayNumber` — the first is the key, the
            // second is renumbered by every structural edit and is derived on read.
            $this->tripStateManager->updateTripAlertsForGroup($tripId, AlertGroup::CULTURAL_POI, $this->groupByStage($alerts));

            $this->publisher->publish($tripId, MercureEventType::CULTURAL_POI_ALERTS, [
                'alerts' => $this->renderForWire($tripId, $alerts),
            ]);
        }, $generation);
    }

    /**
     * Returns the minimum Haversine distance (in metres) from the given
     * point to any geometry point along the route.
     *
     * @param list<Coordinate> $geometry
     */
    private function findMinDistanceToRoute(array $geometry, float $lat, float $lon): int
    {
        $minDist = PHP_FLOAT_MAX;

        foreach ($geometry as $point) {
            $dist = $this->haversine->inMeters($point->lat, $point->lon, $lat, $lon);
            if ($dist < $minDist) {
                $minDist = $dist;
            }
        }

        return (int) round($minDist);
    }
}
