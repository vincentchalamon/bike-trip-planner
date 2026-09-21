<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Concurrency\IfMatch;
use App\Concurrency\TripVersionEtag;
use App\ApiResource\Stage;
use App\Engine\DistanceCalculatorInterface;
use App\Enum\SourceType;
use App\Enum\ComputationTrigger;
use App\Message\RecalculateStages;
use App\Repository\StageWriteResult;
use App\Repository\TripRequestRepositoryInterface;
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
        private DistanceCalculatorInterface $distanceCalculator,
        private TripLocker $tripLocker,
        private StageLocator $stageLocator,
    ) {
    }

    /**
     * @param Delete                                   $operation
     * @param array{tripId?: string, stageId?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $tripId = $uriVariables['tripId'] ?? '';
        $stageId = $uriVariables['stageId'] ?? '';

        $tripRequest = $this->tripStateManager->getRequest($tripId);
        \assert($tripRequest instanceof TripRequest);
        $this->tripLocker->assertNotLocked($tripRequest);

        $sourceType = $this->tripStateManager->getSourceType($tripId);
        $isRestDayDeletion = false;
        $mergedIndex = null;

        // Read, edit and write as one unit: an enrichment worker writing a column in
        // between would otherwise be reverted by the snapshot read here.
        $write = $this->tripStateManager->mutateStages($tripId, function (array $stages) use ($stageId, $sourceType, &$isRestDayDeletion, &$mergedIndex): array {
            $index = $this->stageLocator->indexOf($stages, $stageId);

            if (\count($stages) <= 2) {
                throw new UnprocessableEntityHttpException('Cannot delete stage: minimum 2 stages required.');
            }

            $isRestDayDeletion = $stages[$index]->isRestDay;

            if ($isRestDayDeletion) {
                // Rest days are just removed without merging
                array_splice($stages, $index, 1);
            } elseif ($sourceType === SourceType::KOMOOT_COLLECTION->value) {
                // Single stage or collection: just remove
                array_splice($stages, $index, 1);
            } else {
                // Continuous route with 2+ stages: merge with adjacent stage
                [$stages, $mergedIndex] = $this->mergeWithAdjacent($stages, $index);
            }

            // Reindex day numbers
            foreach ($stages as $i => $stage) {
                $stage->dayNumber = $i + 1;
            }

            return $stages;
        }, IfMatch::expectedVersion($context));

        TripVersionEtag::stamp($context, $write?->version);

        // The trip was asserted to exist above, so the write happened.
        \assert($write instanceof StageWriteResult);

        $stages = $write->stages;
        // The generation comes back from inside the locked write. Re-reading it here would
        // hand us whichever version won the race after the lock was released.
        $generation = $write->version;

        // Only the stage that absorbed the deleted one needs recomputing; a plain
        // removal affects none, which an empty list would read as "all".
        $affected = null !== $mergedIndex && isset($stages[$mergedIndex]) ? [$stages[$mergedIndex]->id] : [];
        // Removing a stage shifts every later one onto a new calendar date; removing a
        // ridden stage also moves the line. Both travel on the one message so the handler
        // dispatches their union once — sending the date set separately here is what would
        // re-run the five computations that sit in both sets twice (ADR-070).
        //
        // Terrain rides along in the date set, which is what restores the "consider a rest
        // day" nudge on the preceding day after a rest day is removed (recette).
        $this->messageBus->dispatch(new RecalculateStages(
            $tripId,
            $affected,
            triggers: $isRestDayDeletion
                ? [ComputationTrigger::DATES]
                : [ComputationTrigger::GEOMETRY, ComputationTrigger::DATES],
            generation: $generation,
        ));
        // Keep the trip's day window in step with the stage count: a trip spans
        // exactly one calendar day per stage (rest days included), so removing a
        // stage shifts the end date back so the global range, the export and a
        // later re-pacing all stay consistent (recette #649). Mirrors
        // StageCreateProcessor / RestDayInsertProcessor. Reuse the request
        // asserted above — storeStages only flushes, never detaches it (review).
        $startDate = $tripRequest->startDate;
        if ($startDate instanceof \DateTimeImmutable) {
            $tripRequest->endDate = $startDate->modify(\sprintf('+%d days', \count($stages) - 1));
            $this->tripStateManager->storeRequest($tripId, $tripRequest);
        }

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

        if ($isLast) {
            // Merge deleted stage into previous: extend previous endPoint
            $previous = $stages[$index - 1];
            $deleted = $stages[$index];
            $previous->endPoint = $deleted->endPoint;
            $previous->geometry = array_merge($previous->geometry, $deleted->geometry);
            $previous->distance = $this->distanceCalculator->calculateTotalDistance($previous->geometry);
            array_splice($stages, $index, 1);

            return [$stages, $index - 1];
        }

        // Merge deleted stage into next: extend next startPoint
        $next = $stages[$index + 1];
        $deleted = $stages[$index];
        $next->startPoint = $deleted->startPoint;
        $next->geometry = array_merge($deleted->geometry, $next->geometry);
        $next->distance = $this->distanceCalculator->calculateTotalDistance($next->geometry);
        array_splice($stages, $index, 1);

        return [$stages, $index];
    }
}
