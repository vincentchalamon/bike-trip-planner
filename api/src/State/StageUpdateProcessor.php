<?php

declare(strict_types=1);

namespace App\State;

use DateTimeImmutable;
use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\StageRequest;
use App\ApiResource\StageResponse;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\RouteSimplifierInterface;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

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
        private ObjectMapperInterface $objectMapper,
        private TripGenerationTrackerInterface $generationTracker,
        private TripLocker $tripLocker,
    ) {
    }

    /**
     * @param StageRequest                        $data
     * @param Patch                               $operation
     * @param array{tripId?: string, index?: int} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $index = \is_numeric($uriVariables['index'] ?? null) ? (int) $uriVariables['index'] : 0;

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        \assert($tripRequest instanceof TripRequest);
        $this->tripLocker->assertNotLocked($tripRequest);

        $stages = $this->tripStateManager->getStages($tripId) ?? [];

        if (!isset($stages[$index])) {
            throw new NotFoundHttpException(\sprintf('Stage at index %d not found.', $index));
        }

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

        // Bump generation: stage edits invalidate in-flight computations
        $generation = $this->generationTracker->increment($tripId);

        // Distance-based editing: walk along decimated route to find new endPoint
        if (null !== $data->distance) {
            $this->applyDistanceChange($tripId, $stages, $index, $data->distance);
            $this->tripStateManager->storeStages($tripId, $stages);

            // Recalculate all affected stages (current and subsequent)
            $affected = range($index, \count($stages) - 1);
            $this->messageBus->dispatch(new RecalculateStages($tripId, $affected, generation: $generation));

            $tripRequest = $this->tripStateManager->getRequest($tripId);
            if ($tripRequest?->startDate instanceof DateTimeImmutable) {
                $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
                $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
            }

            return $this->objectMapper->map($stages[$index], StageResponse::class);
        }

        if ($pointsChanged) {
            $stage->distance = $this->distanceCalculator->distanceBetween(
                $stage->startPoint,
                $stage->endPoint,
            ) / 1000.0;
            $stage->geometry = [$stage->startPoint, $stage->endPoint];
        }

        $stages[$index] = $stage;
        $this->tripStateManager->storeStages($tripId, $stages);

        $this->messageBus->dispatch(new RecalculateStages($tripId, [$index], generation: $generation));

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        if ($tripRequest?->startDate instanceof DateTimeImmutable) {
            $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
            $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
        }

        return $this->objectMapper->map($stage, StageResponse::class);
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
            return;
        }

        /** @var list<Coordinate> $decimatedPoints */
        $decimatedPoints = array_map(
            static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']),
            $rawPoints,
        );

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
