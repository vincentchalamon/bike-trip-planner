<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\StageRequest;
use App\ApiResource\StageResponse;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/**
 * @implements ProcessorInterface<StageRequest, StageResponse>
 */
final readonly class StageMoveProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private MessageBusInterface $messageBus,
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

        if (null === $data->toIndex) {
            throw new UnprocessableEntityHttpException('toIndex is required.');
        }

        $toIndex = $data->toIndex;

        $stages = $this->tripStateManager->getStages($tripId) ?? [];

        if (!isset($stages[$index])) {
            throw new NotFoundHttpException(\sprintf('Stage at index %d not found.', $index));
        }

        if ($toIndex < 0 || $toIndex >= \count($stages)) {
            throw new UnprocessableEntityHttpException(\sprintf('toIndex %d is out of bounds (0-%d).', $toIndex, \count($stages) - 1));
        }

        if ($toIndex === $index) {
            throw new UnprocessableEntityHttpException('toIndex must be different from current index.');
        }

        // Move the stage
        $stage = $stages[$index];
        array_splice($stages, $index, 1);
        array_splice($stages, $toIndex, 0, [$stage]);

        // Reindex day numbers
        foreach ($stages as $i => $s) {
            $s->dayNumber = $i + 1;
        }

        $this->tripStateManager->storeStages($tripId, $stages);

        // Bump generation: stage moves invalidate in-flight computations
        $generation = $this->generationTracker->increment($tripId);

        // Dispatch continuity check for all stages; weather/calendar for all stages
        $this->messageBus->dispatch(new RecalculateStages($tripId, [], generation: $generation));

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        if ($tripRequest?->startDate instanceof \DateTimeImmutable) {
            $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
            $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
        }

        return $this->objectMapper->map($stage, StageResponse::class);
    }
}
