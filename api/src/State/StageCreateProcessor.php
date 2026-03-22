<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Stage;
use App\ApiResource\StageRequest;
use App\ApiResource\StageResponse;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\State\TripLocker;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/**
 * @implements ProcessorInterface<StageRequest, StageResponse>
 */
final readonly class StageCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private MessageBusInterface $messageBus,
        private DistanceCalculatorInterface $distanceCalculator,
        private ObjectMapperInterface $objectMapper,
        private TripGenerationTrackerInterface $generationTracker,
        private TripLocker $tripLocker,
    ) {
    }

    /**
     * @param StageRequest           $data
     * @param Post                   $operation
     * @param array{tripId?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StageResponse
    {
        $tripId = $uriVariables['tripId'] ?? '';

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        if ($tripRequest instanceof \App\ApiResource\TripRequest) {
            $this->tripLocker->assertNotLocked($tripRequest);
        }

        if (null === $data->startPoint || null === $data->endPoint) {
            throw new UnprocessableEntityHttpException('startPoint and endPoint are required to create a stage.');
        }

        $stages = $this->tripStateManager->getStages($tripId) ?? [];

        $position = $data->position ?? \count($stages);

        if ($position < 0 || $position > \count($stages)) {
            throw new UnprocessableEntityHttpException(\sprintf('Position %d is out of bounds (0-%d).', $position, \count($stages)));
        }

        $distance = $this->distanceCalculator
                ->distanceBetween($data->startPoint, $data->endPoint) / 1000.0;

        $newStage = new Stage(
            tripId: $tripId,
            dayNumber: $position + 1,
            distance: $distance,
            elevation: 0.0,
            startPoint: $data->startPoint,
            endPoint: $data->endPoint,
            geometry: [$data->startPoint, $data->endPoint],
            label: $data->label,
        );

        array_splice($stages, $position, 0, [$newStage]);
        $stages = $this->reindexDayNumbers($stages);

        $this->tripStateManager->storeStages($tripId, $stages);

        $generation = $this->generationTracker->increment($tripId);

        $this->messageBus->dispatch(new RecalculateStages($tripId, [$position], generation: $generation));

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        if ($tripRequest?->startDate instanceof \DateTimeImmutable) {
            $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
            $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
        }

        return $this->objectMapper->map($newStage, StageResponse::class);
    }

    /**
     * @param list<Stage> $stages
     *
     * @return list<Stage>
     */
    private function reindexDayNumbers(array $stages): array
    {
        foreach ($stages as $i => $stage) {
            $stage->dayNumber = $i + 1;
        }

        return $stages;
    }
}
