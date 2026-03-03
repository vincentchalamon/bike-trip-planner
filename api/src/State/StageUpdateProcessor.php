<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\StageRequest;
use App\ApiResource\StageResponse;
use App\Engine\DistanceCalculator;
use App\Engine\ElevationCalculator;
use App\Engine\RouteSimplifier;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire(service: 'app.engine_registry')]
        private ContainerInterface $engineRegistry,
        private ObjectMapperInterface $objectMapper,
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

        // Distance-based editing: walk along decimated route to find new endPoint
        if (null !== $data->distance) {
            $this->applyDistanceChange($tripId, $stages, $index, $data->distance);
            $this->tripStateManager->storeStages($tripId, $stages);

            // Recalculate all affected stages (current and subsequent)
            $affected = range($index, \count($stages) - 1);
            $this->messageBus->dispatch(new RecalculateStages($tripId, $affected, true));

            $tripRequest = $this->tripStateManager->getRequest($tripId);
            if ($tripRequest?->startDate instanceof \DateTimeImmutable) {
                $this->messageBus->dispatch(new FetchWeather($tripId));
                $this->messageBus->dispatch(new CheckCalendar($tripId));
            }

            return $this->objectMapper->map($stages[$index], StageResponse::class);
        }

        if ($pointsChanged) {
            $stage->distance = $this->engineRegistry->get(DistanceCalculator::class)->distanceBetween(
                $stage->startPoint,
                $stage->endPoint,
            ) / 1000.0;
            $stage->geometry = [$stage->startPoint, $stage->endPoint];
        }

        $stages[$index] = $stage;
        $this->tripStateManager->storeStages($tripId, $stages);

        $this->messageBus->dispatch(new RecalculateStages($tripId, [$index], true));

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        if ($tripRequest?->startDate instanceof \DateTimeImmutable) {
            $this->messageBus->dispatch(new FetchWeather($tripId));
            $this->messageBus->dispatch(new CheckCalendar($tripId));
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

        /** @var DistanceCalculator $distCalc */
        $distCalc = $this->engineRegistry->get(DistanceCalculator::class);

        /** @var ElevationCalculator $eleCalc */
        $eleCalc = $this->engineRegistry->get(ElevationCalculator::class);

        /** @var RouteSimplifier $simplifier */
        $simplifier = $this->engineRegistry->get(RouteSimplifier::class);

        // Find where the current stage starts in the decimated points
        $startIdx = $distCalc->findClosestIndex($decimatedPoints, $stages[$index]->startPoint);

        // Split at the requested distance
        [$stagePoints, $remaining] = $distCalc->splitAtDistance($decimatedPoints, $startIdx, $requestedKm);

        if (\count($stagePoints) < 2) {
            return;
        }

        // Update current stage
        $stages[$index]->distance = $distCalc->calculateTotalDistance($stagePoints);
        $stages[$index]->elevation = $eleCalc->calculateTotalAscent($stagePoints);
        $stages[$index]->elevationLoss = $eleCalc->calculateTotalDescent($stagePoints);
        $stages[$index]->endPoint = $stagePoints[\count($stagePoints) - 1];
        $stages[$index]->geometry = $simplifier->simplify($stagePoints);

        // Redistribute remaining points among subsequent stages
        $remainingStageCount = \count($stages) - $index - 1;
        if ($remainingStageCount > 0 && [] !== $remaining) {
            // Evenly split remaining distance among subsequent stages
            $remainingDistance = $distCalc->calculateTotalDistance($remaining);
            $targetPerStage = $remainingDistance / $remainingStageCount;

            $currentRemaining = $remaining;
            $counter = \count($stages);
            for ($i = $index + 1; $i < $counter; ++$i) {
                if ($i === \count($stages) - 1 || \count($currentRemaining) < 2) {
                    // Last stage gets everything remaining
                    $slicePoints = $currentRemaining;
                    $currentRemaining = [];
                } else {
                    [$slicePoints, $currentRemaining] = $distCalc->splitAtDistance($currentRemaining, 0, $targetPerStage);
                }

                if (\count($slicePoints) < 2) {
                    continue;
                }

                $stages[$i]->startPoint = $slicePoints[0];
                $stages[$i]->endPoint = $slicePoints[\count($slicePoints) - 1];
                $stages[$i]->distance = $distCalc->calculateTotalDistance($slicePoints);
                $stages[$i]->elevation = $eleCalc->calculateTotalAscent($slicePoints);
                $stages[$i]->elevationLoss = $eleCalc->calculateTotalDescent($slicePoints);
                $stages[$i]->geometry = $simplifier->simplify($slicePoints);
            }
        } elseif (isset($stages[$index + 1])) {
            // No remaining points: next stage starts at our new endpoint
            $stages[$index + 1]->startPoint = $stages[$index]->endPoint;
        }
    }
}
