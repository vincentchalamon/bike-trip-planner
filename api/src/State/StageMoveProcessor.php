<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Stage;
use App\ApiResource\StageRequest;
use App\ApiResource\StageResponse;
use App\Mapper\StageResponseMapper;
use App\Message\CheckCalendar;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Repository\StageWriteResult;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<StageRequest, StageResponse>
 */
final readonly class StageMoveProcessor implements ProcessorInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private MessageBusInterface $messageBus,
        private StageResponseMapper $stageResponseMapper,
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

        $stage = null;

        // Read, edit and write as one unit: an enrichment worker writing a column in
        // between would otherwise be reverted by the snapshot read here.
        $write = $this->tripStateManager->mutateStages($tripId, function (array $stages) use ($index, $toIndex, &$stage): array {
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

            return $stages;
        });

        // The trip was asserted to exist above, so the write happened.
        \assert($write instanceof StageWriteResult);
        // The generation comes back from inside the locked write. Re-reading it here would
        // hand us whichever version won the race after the lock was released.
        $generation = $write->version;

        \assert($stage instanceof Stage);

        // Bump generation: stage moves invalidate in-flight computations
        // Dispatch continuity check for all stages; weather/calendar for all stages
        $this->messageBus->dispatch(new RecalculateStages($tripId, [], generation: $generation));

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        if ($tripRequest?->startDate instanceof \DateTimeImmutable) {
            $this->messageBus->dispatch(new FetchWeather($tripId, $generation));
            $this->messageBus->dispatch(new CheckCalendar($tripId, $generation));
        }

        return $this->stageResponseMapper->map($stage);
    }
}
