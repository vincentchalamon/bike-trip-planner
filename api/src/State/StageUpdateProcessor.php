<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\StageRequest;
use App\ApiResource\StageResponse;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\RouteSimplifierInterface;
use App\Mapper\StageResponseMapper;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\StageWriteResult;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<StageRequest, StageResponse>
 */
final readonly class StageUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private MessageBusInterface $messageBus,
        private DistanceCalculatorInterface $distanceCalculator,
        private ElevationCalculatorInterface $elevationCalculator,
        private RouteSimplifierInterface $routeSimplifier,
        private StageResponseMapper $stageResponseMapper,
        private TripLocker $tripLocker,
        private StageLocator $stageLocator,
    ) {
    }

    /**
     * @param StageRequest                             $data
     * @param Patch                                    $operation
     * @param array{tripId?: string, stageId?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $stageId = $uriVariables['stageId'] ?? '';
        $index = 0;

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        \assert($tripRequest instanceof TripRequest);
        $this->tripLocker->assertNotLocked($tripRequest);

        $stage = null;

        // Read, edit and write as one unit: an enrichment worker writing a column in
        // between would otherwise be reverted by the snapshot read here. Both editing
        // modes (explicit points and distance-driven split) share the one critical
        // section; only the set of stages to recalculate differs afterwards.
        $write = $this->tripStateManager->mutateStages($tripId, function (array $stages) use ($tripId, $data, $stageId, &$index, &$stage): array {
            $index = $this->stageLocator->indexOf($stages, $stageId);

            $stage = $stages[$index];
            $pointsChanged = false;

            if (null !== $data->startPoint) {
                $stage->startPoint = $data->startPoint;
                $pointsChanged = true;
            }

            if (null !== $data->endPoint) {
                $stage->endPoint = $data->endPoint;
                $pointsChanged = true;
            }

            if (null !== $data->label) {
                $stage->label = $data->label;
            }

            // Distance-based editing: walk along decimated route to find new endPoint
            if (null !== $data->distance) {
                $this->applyDistanceChange($tripId, $stages, $index, $data->distance);

                return $stages;
            }

            if ($pointsChanged) {
                $stage->distance = $this->distanceCalculator->distanceBetween(
                    $stage->startPoint,
                    $stage->endPoint,
                ) / 1000.0;
                $stage->geometry = [$stage->startPoint, $stage->endPoint];
            }

            $stages[$index] = $stage;

            return array_values($stages);
        });

        // The trip was asserted to exist above, so the write happened.
        \assert($write instanceof StageWriteResult);

        $stages = $write->stages;
        // The generation comes back from inside the locked write. Re-reading it here would
        // hand us whichever version won the race after the lock was released.
        $generation = $write->version;

        \assert($stage instanceof Stage);

        // Bump generation: stage edits invalidate in-flight computations. After the
        // write, so the generation names the state that was actually persisted.
        // A distance edit cascades into every following stage; a point or label edit
        // touches only this one.
        $affected = null !== $data->distance
            ? array_map(static fn (Stage $s): string => $s->id, \array_slice($stages, $index))
            : [$stage->id];
        $this->messageBus->dispatch(new RecalculateStages($tripId, $affected, generation: $generation));

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        if ($tripRequest?->startDate instanceof \DateTimeImmutable) {
            $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
            $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
        }

        return $this->stageResponseMapper->map($stages[$index] ?? $stage);
    }

    /**
     * Rebuilds the full decimated route from the persisted per-stage geometry, used
     * as a fallback when the transient Redis copy has expired. The stages are
     * contiguous segments of that route sharing their boundary point
     * (stage[i].end == stage[i+1].start), so the first point of every stage after
     * the first is dropped to avoid duplicating the previous stage's last point.
     * Rest days carry no geometry and simply contribute nothing.
     *
     * @param list<Stage> $stages
     *
     * @return list<Coordinate>
     */
    private function reconstructDecimatedPoints(array $stages): array
    {
        $points = [];
        $first = true;
        foreach ($stages as $stage) {
            foreach ($stage->geometry as $offset => $point) {
                if (!$first && 0 === $offset) {
                    continue;
                }

                $points[] = $point;
            }

            if ([] !== $stage->geometry) {
                $first = false;
            }
        }

        return $points;
    }

    /**
     * Walks along the decimated route to split stages based on the requested distance.
     *
     * @param list<Stage> $stages
     */
    private function applyDistanceChange(string $tripId, array &$stages, int $index, float $requestedKm): void
    {
        $rawPoints = $this->tripStateManager->getDecimatedPoints($tripId);
        if (null === $rawPoints || [] === $rawPoints) {
            // The transient Redis copy has expired (TTL): rebuild the route from the
            // persisted per-stage geometry (ADR-057) so a distance edit on an older
            // trip still applies instead of silently no-op'ing.
            $decimatedPoints = $this->reconstructDecimatedPoints($stages);
        } else {
            /** @var list<Coordinate> $decimatedPoints */
            $decimatedPoints = array_map(
                static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']),
                $rawPoints,
            );
        }

        if (\count($decimatedPoints) < 2) {
            return;
        }

        // Find where the current stage starts in the decimated points
        $startIdx = $this->distanceCalculator->findClosestIndex($decimatedPoints, $stages[$index]->startPoint);

        // Split at the requested distance
        [$stagePoints, $remaining] = $this->distanceCalculator->splitAtDistance($decimatedPoints, $startIdx, $requestedKm);

        if (\count($stagePoints) < 2) {
            return;
        }

        // Update current stage
        $stages[$index]->distance = $this->distanceCalculator->calculateTotalDistance($stagePoints);
        $stages[$index]->elevation = $this->elevationCalculator->calculateTotalAscent($stagePoints);
        $stages[$index]->elevationLoss = $this->elevationCalculator->calculateTotalDescent($stagePoints);
        $stages[$index]->endPoint = $stagePoints[\count($stagePoints) - 1];
        $stages[$index]->geometry = $this->routeSimplifier->simplify($stagePoints);

        // Report remaining km to the next stage, or create a new one
        if ([] !== $remaining && \count($remaining) >= 2) {
            if (isset($stages[$index + 1])) {
                // Remaining km absorbed by the next stage (its startPoint changes, its endPoint stays)
                $nextEndIdx = $this->distanceCalculator->findClosestIndex($decimatedPoints, $stages[$index + 1]->endPoint);
                $newStartIdx = $this->distanceCalculator->findClosestIndex($decimatedPoints, $remaining[0]);
                $nextPoints = \array_slice($decimatedPoints, $newStartIdx, $nextEndIdx - $newStartIdx + 1);

                if (\count($nextPoints) >= 2) {
                    $stages[$index + 1]->startPoint = $nextPoints[0];
                    $stages[$index + 1]->distance = $this->distanceCalculator->calculateTotalDistance($nextPoints);
                    $stages[$index + 1]->elevation = $this->elevationCalculator->calculateTotalAscent($nextPoints);
                    $stages[$index + 1]->elevationLoss = $this->elevationCalculator->calculateTotalDescent($nextPoints);
                    $stages[$index + 1]->geometry = $this->routeSimplifier->simplify($nextPoints);
                } else {
                    // Fallback: keep stages contiguous even when index resolution collapses
                    $stages[$index + 1]->startPoint = $stages[$index]->endPoint;
                }
            } else {
                // Last stage: create a new stage with the remaining points
                $stages[] = new Stage(
                    tripId: $tripId,
                    dayNumber: $stages[$index]->dayNumber + 1,
                    distance: $this->distanceCalculator->calculateTotalDistance($remaining),
                    elevation: $this->elevationCalculator->calculateTotalAscent($remaining),
                    startPoint: $remaining[0],
                    endPoint: $remaining[\count($remaining) - 1],
                    geometry: $this->routeSimplifier->simplify($remaining),
                    elevationLoss: $this->elevationCalculator->calculateTotalDescent($remaining),
                );
            }
        }
    }
}
