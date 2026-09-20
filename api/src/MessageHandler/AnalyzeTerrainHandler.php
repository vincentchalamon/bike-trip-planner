<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertRenderer;
use App\Analyzer\AnalyzerRegistryInterface;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertGroup;
use App\Enum\ComputationName;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\StagePayloadMapper;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeTerrain;
use App\Osm\WaysRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class AnalyzeTerrainHandler extends AbstractTripMessageHandler
{
    /**
     * Corridor half-width (m) for the local-first ways reads (ADR-040). Sized on
     * the route's own positional error -- Douglas-Peucker decimation at 20 m
     * (ADR-004) dominates GPS noise -- so a way actually ridden stays inside it,
     * while roads merely running alongside (greenway next to a trunk road) fall
     * out instead of being counted as ridden.
     */
    private const int WAYS_CORRIDOR_RADIUS_METERS = 20;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private AnalyzerRegistryInterface $analyzerRegistry,
        private WaysRepositoryInterface $waysRepository,
        private GeometryDistributorInterface $distributor,
        private StagePayloadMapper $stagePayloadMapper,
        MessageBusInterface $messageBus,
        AlertRenderer $alertRenderer,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus, $alertRenderer);
    }

    public function __invoke(AnalyzeTerrain $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages || [] === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';
        $request = $this->tripStateManager->getRequest($tripId);
        $ebikeMode = (bool) $request?->ebikeMode;
        $startDate = $request?->startDate;
        $departureHour = $request?->departureHour ?? 8; // @phpstan-ignore nullsafe.neverNull
        $averageSpeed = $request?->averageSpeed ?? 15.0; // @phpstan-ignore nullsafe.neverNull

        $this->executeWithTracking($tripId, ComputationName::TERRAIN, function () use ($tripId, $stages, $locale, $ebikeMode, $startDate, $departureHour, $averageSpeed): void {
            $waysByStage = $this->fetchOsmWaysByStage($tripId, $stages);
            $stageCount = \count($stages);
            $alertsData = [];
            $renderedByStage = [];

            for ($i = 0; $i < $stageCount; ++$i) {
                $stage = $stages[$i];
                $context = [
                    'nextStage' => $stages[$i + 1] ?? null,
                    'tripDays' => $stageCount,
                    'ebikeMode' => $ebikeMode,
                    'osmWays' => $waysByStage[$i] ?? [],
                    'allStages' => $stages,
                    'startDate' => $startDate,
                    'stageId' => $stage->id,
                    'departureHour' => $departureHour,
                    'averageSpeed' => $averageSpeed,
                ];

                // Built once, in the shape that goes both to the database and to the wire:
                // the two consumers cannot drift when they read the same array (ADR-068).
                // Coordinates and contextual actions belong to that shape — the frontend
                // must be able to zoom to a discontinuity without a reload (issue #863).
                //
                // Keyed by stage identity, like every stage-scoped event since ADR-066.
                $alertsData[$stage->id] = array_map(
                    $this->stagePayloadMapper->alertToPayload(...),
                    $this->analyzerRegistry->analyze($stage, $context),
                );
                // The wire copy is the same array read in the trip's language (ADR-069).
                // Rendered per stage because the owning day number is what `%stage%` and
                // the continuity pair resolve against, and it is deliberately not stored.
                $renderedByStage[$stage->id] = $this->alertRenderer->render($alertsData[$stage->id], $stage->dayNumber, $locale);
            }

            $this->tripStateManager->updateTripAlertsForGroup($tripId, AlertGroup::TERRAIN, $alertsData);

            $this->publisher->publish($tripId, MercureEventType::TERRAIN_ALERTS, [
                'alertsByStage' => $renderedByStage,
            ]);
        }, $generation);
    }

    /**
     * Reads OSM ways along the route from the local-first index and distributes
     * them to stages (ADR-040). The index already reduces each way to the centroid
     * and length (m) of its portion inside the corridor plus the surface/traffic
     * tags, so no per-way geometry math is needed here.
     *
     * @param list<Stage> $stages
     *
     * @return array<int, list<array{lat: float, lon: float, surface: string, tracktype: string, smoothness: string, highway: string, cycleway: string, 'cycleway:right': string, 'cycleway:left': string, 'cycleway:both': string, bicycle: string, maxspeed: string, length: float, geometry: list<list<array{0: float, 1: float}>>}>>
     */
    private function fetchOsmWaysByStage(string $tripId, array $stages): array
    {
        $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);
        $points = null !== $decimatedData
            ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $decimatedData)
            : array_merge(...array_map(
                static fn (Stage $stage): array => $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                $stages,
            ));

        $route = array_map(static fn (Coordinate $point): array => ['lat' => $point->lat, 'lon' => $point->lon], $points);

        $ways = $this->waysRepository->findInCorridor($route, self::WAYS_CORRIDOR_RADIUS_METERS);

        return $this->distributor->distributeByGeometry($ways, $stages);
    }
}
