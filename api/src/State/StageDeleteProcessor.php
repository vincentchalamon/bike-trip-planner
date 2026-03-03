<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculator;
use App\Enum\SourceType;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<null, void>
 */
final readonly class StageDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private MessageBusInterface $messageBus,
        #[Autowire(service: 'app.engine_registry')]
        private ContainerInterface $engineRegistry,
    ) {
    }

    /**
     * @param Delete                              $operation
     * @param array{tripId?: string, index?: int} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $index = \is_numeric($uriVariables['index'] ?? null) ? (int) $uriVariables['index'] : 0;

        $stages = $this->tripStateManager->getStages($tripId) ?? [];

        if (!isset($stages[$index])) {
            throw new NotFoundHttpException(\sprintf('Stage at index %d not found.', $index));
        }

        if (\count($stages) <= 2) {
            throw new UnprocessableEntityHttpException('Cannot delete stage: minimum 2 stages required.');
        }

        $sourceType = $this->tripStateManager->getSourceType($tripId);

        if ($sourceType === SourceType::KOMOOT_COLLECTION->value) {
            // Single stage or collection: just remove
            array_splice($stages, $index, 1);
            $mergedIndex = null;
        } else {
            // Continuous route with 2+ stages: merge with adjacent stage
            [$stages, $mergedIndex] = $this->mergeWithAdjacent($stages, $index);
        }

        // Reindex day numbers
        foreach ($stages as $i => $stage) {
            $stage->dayNumber = $i + 1;
        }

        $this->tripStateManager->storeStages($tripId, $stages);

        $affectedIndices = null !== $mergedIndex ? [$mergedIndex] : [];
        $this->messageBus->dispatch(new RecalculateStages($tripId, $affectedIndices, true));

        $this->messageBus->dispatch(new FetchWeather($tripId));
        $this->messageBus->dispatch(new CheckCalendar($tripId));
    }

    /**
     * Merges the stage at $index with the next stage (or previous if it's the last).
     * Returns updated stages and the index of the merged stage.
     *
     * @param list<Stage> $stages
     *
     * @return array{list<Stage>, int}
     */
    private function mergeWithAdjacent(array $stages, int $index): array
    {
        $isLast = $index === \count($stages) - 1;

        $distanceCalculator = $this->engineRegistry->get(DistanceCalculator::class);

        if ($isLast) {
            // Merge deleted stage into previous: extend previous endPoint
            $previous = $stages[$index - 1];
            $deleted = $stages[$index];
            $previous->endPoint = $deleted->endPoint;
            $previous->geometry = array_merge($previous->geometry, $deleted->geometry);
            $previous->distance = $distanceCalculator->calculateTotalDistance($previous->geometry);
            array_splice($stages, $index, 1);

            return [$stages, $index - 1];
        }

        // Merge deleted stage into next: extend next startPoint
        $next = $stages[$index + 1];
        $deleted = $stages[$index];
        $next->startPoint = $deleted->startPoint;
        $next->geometry = array_merge($deleted->geometry, $next->geometry);
        $next->distance = $distanceCalculator->calculateTotalDistance($next->geometry);
        array_splice($stages, $index, 1);

        return [$stages, $index];
    }
}
