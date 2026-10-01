<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertPayload;
use App\Analyzer\AnalyzerRegistryInterface;
use App\Analyzer\StageAnalysisContext;
use App\ApiResource\TripRequest;
use App\ApiResource\Stage;
use App\Entity\User;
use App\Enum\AlertGroup;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Message\AnalyzeTerrain;
use App\Osm\WaysRepositoryInterface;
use App\Repository\TransientTripPointsStoreInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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
        TripHandlerContext $context,
        private TransientTripPointsStoreInterface $points,
        private AnalyzerRegistryInterface $analyzerRegistry,
        private WaysRepositoryInterface $waysRepository,
        private GeometryDistributorInterface $distributor,
    ) {
        parent::__construct($context);
    }

    public function __invoke(AnalyzeTerrain $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages || [] === $stages) {
            return;
        }

        $locale = $this->tripRequestRepository->getLocale($tripId) ?? User::FALLBACK_LOCALE;
        $request = $this->tripRequestRepository->getRequest($tripId);
        $ebikeMode = (bool) $request?->ebikeMode;
        $startDate = $request?->startDate;
        $departureHour = $request?->departureHour ?? TripRequest::DEFAULT_DEPARTURE_HOUR; // @phpstan-ignore nullsafe.neverNull
        $averageSpeed = $request?->averageSpeed ?? TripRequest::DEFAULT_AVERAGE_SPEED; // @phpstan-ignore nullsafe.neverNull

        $this->executeWithTracking($message, function () use ($tripId, $stages, $locale, $ebikeMode, $startDate, $departureHour, $averageSpeed): void {
            $waysByStage = $this->fetchOsmWaysByStage($tripId, $stages);
            $stageCount = \count($stages);
            $alertsData = [];
            $renderedByStage = [];

            for ($i = 0; $i < $stageCount; ++$i) {
                $stage = $stages[$i];
                $context = new StageAnalysisContext(
                    nextStage: $stages[$i + 1] ?? null,
                    allStages: $stages,
                    ebikeMode: $ebikeMode,
                    osmWays: $waysByStage[$i] ?? [],
                    startDate: $startDate,
                    departureHour: $departureHour,
                    averageSpeed: $averageSpeed,
                );

                // Built once, in the shape that goes both to the database and to the wire:
                // the two consumers cannot drift when they read the same array (ADR-068).
                // Coordinates and contextual actions belong to that shape — the frontend
                // must be able to zoom to a discontinuity without a reload (issue #863).
                //
                // Keyed by stage identity, like every stage-scoped event since ADR-066.
                $alertsData[$stage->id] = array_map(
                    AlertPayload::of(...),
                    $this->analyzerRegistry->analyze($stage, $context),
                );
                // The wire copy is the same array read in the trip's language (ADR-069).
                // Rendered per stage because the owning day number is what `%stage%` and
                // the continuity pair resolve against, and it is deliberately not stored.
                $renderedByStage[$stage->id] = $this->alertRenderer->render($alertsData[$stage->id], $stage->dayNumber, $locale);
            }

            $this->stageStore->updateTripAlertsForGroup($tripId, AlertGroup::TERRAIN, $alertsData);

            $this->publisher->publish($tripId, MercureEventType::TERRAIN_ALERTS, [
                'alertsByStage' => $renderedByStage,
            ]);
        });
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
        $route = $this->routeCorridor($this->points, $tripId, $stages);

        $ways = $this->waysRepository->findInCorridor($route, self::WAYS_CORRIDOR_RADIUS_METERS);

        return $this->distributor->distributeByGeometry($ways, $stages);
    }
}
