<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\CulturalPoiSource\CulturalPoiSourceRegistry;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckCulturalPois;
use App\Repository\TripRequestRepositoryInterface;
use App\Wikidata\WikidataEnricherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private TranslatorInterface $translator,
        private WikidataEnricherInterface $wikidataEnricher,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
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
                $this->publisher->publish($tripId, MercureEventType::CULTURAL_POI_ALERTS, [
                    'alerts' => [],
                ]);

                return;
            }

            // Fetch all POIs from all enabled sources
            $allCulturalPois = $this->registry->fetchAllForStages($stageGeometries, self::CULTURAL_POI_RADIUS_METERS);

            // Wikidata enrichment pass over all POIs (batch SPARQL)
            $qIds = array_values(array_filter(array_unique(array_column($allCulturalPois, 'wikidataId'))));
            $wikidataEnrichments = [] !== $qIds ? $this->wikidataEnricher->enrichBatch($qIds, $locale) : [];

            if ([] !== $wikidataEnrichments) {
                foreach ($allCulturalPois as $k => $poi) {
                    $qId = $poi['wikidataId'] ?? null;
                    if (null !== $qId && isset($wikidataEnrichments[$qId])) {
                        $wikidata = $wikidataEnrichments[$qId];
                        // Wikidata never overwrites an already-filled field
                        $allCulturalPois[$k] = array_merge($wikidata, $poi);
                    }
                }
            }

            // Distribute POIs to the nearest active stage via geometry
            /** @var array<int, list<array>> $poisByActiveStage */
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
                    $alertMessage = $this->translator->trans(
                        'alert.cultural_poi.suggestion',
                        [
                            '%stage%' => $stage->dayNumber,
                            '%name%' => $poi['name'],
                            '%type%' => $poi['type'],
                            '%distance%' => $poi['distanceFromRoute'],
                        ],
                        'alerts',
                        $locale,
                    );

                    $alert = [
                        'stageIndex' => $originalIndex,
                        'dayNumber' => $stage->dayNumber,
                        'type' => AlertType::NUDGE->value,
                        'message' => $alertMessage,
                        'lat' => $poi['lat'],
                        'lon' => $poi['lon'],
                        'poiName' => $poi['name'],
                        'poiType' => $poi['type'],
                        'poiLat' => $poi['lat'],
                        'poiLon' => $poi['lon'],
                        'distanceFromRoute' => $poi['distanceFromRoute'],
                    ];

                    if (null !== ($poi['openingHours'] ?? null)) {
                        $alert['openingHours'] = $poi['openingHours'];
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

                    if (null !== ($poi['source'] ?? null)) {
                        $alert['source'] = $poi['source'];
                    }

                    if (null !== ($poi['imageUrl'] ?? null)) {
                        $alert['imageUrl'] = $poi['imageUrl'];
                    }

                    if (null !== ($poi['wikipediaUrl'] ?? null)) {
                        $alert['wikipediaUrl'] = $poi['wikipediaUrl'];
                    }

                    $alerts[] = $alert;
                }
            }

            $this->publisher->publish($tripId, MercureEventType::CULTURAL_POI_ALERTS, [
                'alerts' => $alerts,
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
