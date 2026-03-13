<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Stage;
use App\ApiResource\StageResponse;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/**
 * @implements ProcessorInterface<null, StageResponse>
 */
final readonly class RestDayInsertProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private MessageBusInterface $messageBus,
        private ObjectMapperInterface $objectMapper,
    ) {
    }

    /**
     * @param null                                $data
     * @param Post                                $operation
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

        $afterStage = $stages[$index];

        // The rest day sits between $index and $index+1.
        // startPoint = endPoint of the previous stage (same location).
        $restDay = new Stage(
            tripId: $tripId,
            dayNumber: $index + 2,
            distance: 0.0,
            elevation: 0.0,
            startPoint: $afterStage->endPoint,
            endPoint: $afterStage->endPoint,
            geometry: [$afterStage->endPoint],
            label: null,
            elevationLoss: 0.0,
            isRestDay: true,
        );

        array_splice($stages, $index + 1, 0, [$restDay]);

        // Reindex day numbers
        foreach ($stages as $i => $stage) {
            $stage->dayNumber = $i + 1;
        }

        $this->tripStateManager->storeStages($tripId, $stages);

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        if ($tripRequest?->startDate instanceof \DateTimeImmutable) {
            $this->messageBus->dispatch(new FetchWeather($tripId));
            $this->messageBus->dispatch(new CheckCalendar($tripId));
        }

        return $this->objectMapper->map($restDay, StageResponse::class);
    }
}
