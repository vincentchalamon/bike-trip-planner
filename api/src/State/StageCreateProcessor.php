<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Stage;
use App\ApiResource\StageRequest;
use App\ApiResource\StageResponse;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Mapper\StageResponseMapper;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<StageRequest, StageResponse>
 */
final readonly class StageCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private MessageBusInterface $messageBus,
        private DistanceCalculatorInterface $distanceCalculator,
        private StageResponseMapper $stageResponseMapper,
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
        \assert($tripRequest instanceof TripRequest);
        $this->tripLocker->assertNotLocked($tripRequest);

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

        // Keep the trip's day window in step with the stage count: a trip spans
        // exactly one calendar day per stage (rest days included), so adding a
        // stage shifts the end date forward so the global range, the export and a
        // later re-pacing all stay consistent (recette #649).
        $tripRequest = $this->tripStateManager->getRequest($tripId);
        $startDate = $tripRequest?->startDate;
        if ($tripRequest instanceof TripRequest && $startDate instanceof \DateTimeImmutable) {
            $tripRequest->endDate = $startDate->modify(\sprintf('+%d days', \count($stages) - 1));
            $this->tripStateManager->storeRequest($tripId, $tripRequest);
            $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
            $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
        }

        return $this->stageResponseMapper->map($newStage);
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
