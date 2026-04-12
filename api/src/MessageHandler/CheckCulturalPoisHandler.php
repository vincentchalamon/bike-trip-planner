<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckCulturalPois;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Detects cultural POIs (museums, monuments, castles, churches, viewpoints)
 * within 500 m of each stage route and emits SUGGESTION alerts.
 *
 * Each alert carries the POI coordinates so the frontend can display an
 * "add to itinerary" button that triggers route recalculation via
 * RecalculateRouteSegment (ADR-017).
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

    /**
     * Overpass `historic=*` values that are considered notable enough to suggest.
     *
     * @var list<string>
     */
    private const array NOTABLE_HISTORIC_VALUES = [
        'castle',
        'monument',
        'memorial',
        'ruins',
        'archaeological_site',
        'church',
        'cathedral',
        'abbey',
        'fort',
    ];

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private GeometryDistributorInterface $distributor,
        private GeoDistanceInterface $haversine,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger);
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
            /** @var list<list<Coordinate>> $stageGeometries */
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
                $stageGeometries[] = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
            }

            if ([] === $stageGeometries) {
                $this->publisher->publish($tripId, MercureEventType::CULTURAL_POI_ALERTS, [
                    'alerts' => [],
                ]);

                return;
            }

            // Single batch query for all active stages
            $query = $this->queryBuilder->buildBatchCulturalPoiQuery($stageGeometries, self::CULTURAL_POI_RADIUS_METERS);
            $result = $this->scanner->query($query);

            /** @var list<array{tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
            $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

            // Parse all cultural POIs from the batch result
            /** @var list<array{name: string, type: string, lat: float, lon: float}> $allCulturalPois */
            $allCulturalPois = [];
            foreach ($elements as $element) {
                $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                if (null === $lat || null === $lon) {
                    continue;
                }

                $tags = $element['tags'] ?? [];
                $poiType = $this->resolveCulturalPoiType($tags);

                if (null === $poiType) {
                    continue;
                }

                $name = $tags['name'] ?? $poiType;

                $allCulturalPois[] = [
                    'name' => $name,
                    'type' => $poiType,
                    'lat' => (float) $lat,
                    'lon' => (float) $lon,
                ];
            }

            // Distribute POIs to the nearest active stage via geometry
            /** @var array<int, list<array{name: string, type: string, lat: float, lon: float}>> $poisByActiveStage */
            $poisByActiveStage = $this->distributor->distributeByGeometry($allCulturalPois, $activeStages);

            $alerts = [];
            foreach ($activeStages as $activeIdx => $stage) {
                $originalIndex = $activeStageIndices[$activeIdx];
                $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];

                $stagePois = [];
                foreach ($poisByActiveStage[$activeIdx] ?? [] as $poi) {
                    $distanceFromRoute = $this->findMinDistanceToRoute($geometry, $poi['lat'], $poi['lon']);

                    $stagePois[] = [
                        'name' => $poi['name'],
                        'type' => $poi['type'],
                        'lat' => $poi['lat'],
                        'lon' => $poi['lon'],
                        'distanceFromRoute' => $distanceFromRoute,
                    ];
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

                    $alerts[] = [
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
                }
            }

            $this->publisher->publish($tripId, MercureEventType::CULTURAL_POI_ALERTS, [
                'alerts' => $alerts,
            ]);
        }, $generation);
    }

    /**
     * Resolves the human-readable POI type from OSM tags.
     * Returns null when the element does not qualify as a notable cultural POI.
     *
     * @param array<string, string> $tags
     */
    private function resolveCulturalPoiType(array $tags): ?string
    {
        if (isset($tags['tourism'])) {
            return match ($tags['tourism']) {
                'museum' => 'museum',
                'attraction' => 'attraction',
                'viewpoint' => 'viewpoint',
                default => null,
            };
        }

        if (isset($tags['historic']) && \in_array($tags['historic'], self::NOTABLE_HISTORIC_VALUES, true)) {
            return $tags['historic'];
        }

        return null;
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
