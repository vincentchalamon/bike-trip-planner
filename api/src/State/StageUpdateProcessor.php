<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\StageRequest;
use App\ApiResource\StageResponse;
use App\Engine\DistanceCalculator;
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
}
