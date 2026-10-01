<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\PacingEngineInterface;
use App\Engine\RouteSimplifierInterface;
use App\Engine\StageRoute;
use App\Enum\SourceType;
use App\Repository\TransientTripPointsStoreInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;

/**
 * Generates the structural stages of a trip (pacing) from its stored route data.
 *
 * Extracted from {@see \App\MessageHandler\GenerateStagesHandler} (ADR-043) so the
 * exact same pacing — including the Komoot-collection multi-track case — can run both:
 *  - synchronously in {@see GpxUploadService} (the GPX upload is fully local CPU work),
 *  - asynchronously in the link/AI path that still needs a network fetch first.
 *
 * No DB write, no network. The caller persists and publishes.
 *
 * The route is read from the transient points while they last, and from the stages' own
 * geometry once they have expired ({@see StageRoute}, #1405): a trip has to stay re-pacable
 * whatever the cache holds.
 */
final readonly class StructuralComputationService
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private TransientTripPointsStoreInterface $points,
        private DistanceCalculatorInterface $distanceCalculator,
        private ElevationCalculatorInterface $elevationCalculator,
        private RouteSimplifierInterface $routeSimplifier,
        private PacingEngineInterface $pacingEngine,
        private TripStageStoreInterface $stageStore,
    ) {
    }

    /**
     * Computes the stages for a trip from its stored route data.
     *
     * Routes the Komoot-collection source type to per-track stage generation and
     * everything else to the fatigue-aware pacing engine, preserving the exact
     * behaviour previously inlined in {@see \App\MessageHandler\GenerateStagesHandler}.
     *
     * @return list<Stage>
     */
    public function generateStages(string $tripId, TripRequest $request): array
    {
        $sourceType = $this->tripStateManager->getSourceType($tripId);

        if ($sourceType === SourceType::KOMOOT_COLLECTION->value) {
            return $this->generateCollectionStages($tripId);
        }

        return $this->generatePacingStages($tripId, $request);
    }

    /**
     * Serializes stages into the `stages_computed` Mercure event payload shape.
     *
     * Shared by both structural callers (GPX upload service and the link handler)
     * so the wire format stays single-sourced.
     *
     * @param list<Stage> $stages
     *
     * @return list<array<string, mixed>>
     */
    public function serializeStagesForEvent(array $stages): array
    {
        return array_map(
            static fn (Stage $s): array => [
                'dayNumber' => $s->dayNumber,
                'distance' => round($s->distance, 1),
                'elevation' => (int) $s->elevation,
                'elevationLoss' => (int) $s->elevationLoss,
                'startPoint' => [
                    'lat' => $s->startPoint->lat,
                    'lon' => $s->startPoint->lon,
                    'ele' => $s->startPoint->ele,
                ],
                'endPoint' => [
                    'lat' => $s->endPoint->lat,
                    'lon' => $s->endPoint->lon,
                    'ele' => $s->endPoint->ele,
                ],
                'label' => $s->label,
                'geometry' => array_map(
                    static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                    $s->geometry,
                ),
            ],
            $stages,
        );
    }

    /** @return list<Stage> */
    private function generateCollectionStages(string $tripId): array
    {
        $tracksData = $this->points->getTracksData($tripId);
        $tracks = null !== $tracksData
            ? array_map(static fn (array $track): array => array_map(
                static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']),
                $track,
            ), $tracksData)
            : StageRoute::tracks($this->stageStore->getStages($tripId) ?? []);

        $stages = [];

        foreach ($tracks as $i => $points) {
            if ([] === $points) {
                continue;
            }

            $distance = $this->distanceCalculator->calculateTotalDistance($points);
            $elevation = $this->elevationCalculator->calculateTotalAscent($points);
            $elevationLoss = $this->elevationCalculator->calculateTotalDescent($points);
            $geometry = $this->routeSimplifier->simplify($points);

            $stages[] = new Stage(
                tripId: $tripId,
                dayNumber: $i + 1,
                distance: $distance,
                elevation: $elevation,
                startPoint: $points[0],
                endPoint: $points[\count($points) - 1],
                geometry: $geometry,
                elevationLoss: $elevationLoss,
            );
        }

        return $stages;
    }

    /**
     * @return list<Stage>
     */
    private function generatePacingStages(string $tripId, TripRequest $request): array
    {
        $decimatedData = $this->points->getDecimatedPoints($tripId);
        $decimatedPoints = null !== $decimatedData
            ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $decimatedData)
            : StageRoute::points($this->stageStore->getStages($tripId) ?? []);

        if ([] === $decimatedPoints) {
            return [];
        }

        $allPointsData = null !== $decimatedData ? $this->points->getRawPoints($tripId) : null;
        $allPoints = null !== $allPointsData
            ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $allPointsData)
            : $decimatedPoints;

        $totalDistance = $this->distanceCalculator->calculateTotalDistance($allPoints);

        if ($request->endDate instanceof \DateTimeImmutable && $request->startDate instanceof \DateTimeImmutable) {
            $numberOfDays = (int) $request->startDate->diff($request->endDate)->days + 1;
        } else {
            $numberOfDays = (int) ceil($totalDistance / $request->maxDistancePerDay);
            $numberOfDays = max(1, $numberOfDays);
        }

        // Pass raw points for accurate elevation calculation (decimated points lose altitude detail).
        // rawPoints is null when no full-resolution data was loaded (e.g. routing-only trips),
        // in which case the pacing engine falls back to decimated points.
        $rawPoints = null !== $allPointsData ? $allPoints : null;

        return $this->pacingEngine->generateStages(
            $tripId,
            $decimatedPoints,
            $numberOfDays,
            $totalDistance,
            $request->fatigueFactor,
            $request->elevationPenalty,
            $rawPoints,
            $request->maxDistancePerDay,
        );
    }
}
