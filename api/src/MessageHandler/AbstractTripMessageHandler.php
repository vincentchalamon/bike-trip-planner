<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;

abstract readonly class AbstractTripMessageHandler
{
    public function __construct(
        protected ComputationTrackerInterface $computationTracker,
        protected TripUpdatePublisherInterface $publisher,
        protected TripGenerationTrackerInterface $generationTracker,
        protected LoggerInterface $logger,
        protected TripRequestRepositoryInterface $tripRequestRepository,
    ) {
    }

    /**
     * Returns true when the message generation is outdated.
     *
     * A null generation means the message was dispatched without versioning
     * (e.g. cascading child messages) — these are never considered stale.
     */
    protected function isStale(string $tripId, ?int $messageGeneration): bool
    {
        if (null === $messageGeneration) {
            return false;
        }

        $current = $this->generationTracker->current($tripId);

        if (null === $current) {
            return false;
        }

        return $messageGeneration < $current;
    }

    /**
     * Executes the handler body with computation tracking.
     * Marks computation as running, executes callback, then marks done.
     * On exception: marks failed, publishes error event, re-throws for retry.
     *
     * @throws \Throwable
     */
    protected function executeWithTracking(
        string $tripId,
        ComputationName $computation,
        callable $callback,
        ?int $messageGeneration = null,
    ): void {
        if ($this->isStale($tripId, $messageGeneration)) {
            $this->logger->info('Discarding stale message.', [
                'tripId' => $tripId,
                'computation' => $computation->value,
                'messageGeneration' => $messageGeneration,
                'currentGeneration' => $this->generationTracker->current($tripId),
            ]);

            return;
        }

        $this->computationTracker->markRunning($tripId, $computation);

        $startTime = hrtime(true);

        try {
            $callback();
            $duration = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $this->computationTracker->markDone($tripId, $computation);
            $this->logger->info('Handler {name} completed in {duration}ms.', [
                'name' => $computation->value,
                'duration' => $duration,
                'tripId' => $tripId,
            ]);
        } catch (\Throwable $throwable) {
            $duration = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $this->computationTracker->markFailed($tripId, $computation);
            $this->publisher->publishComputationError($tripId, $computation->value, $throwable->getMessage());
            $this->logger->warning('Handler {name} failed after {duration}ms.', [
                'name' => $computation->value,
                'duration' => $duration,
                'tripId' => $tripId,
            ]);

            throw $throwable;
        }

        // Mode 1 — progress bar: publish a business-data-free progress event
        // after every handler completes, so the frontend can drive its narrative stepper.
        $this->publishProgress($tripId, $computation);

        if ($this->computationTracker->isAllComplete($tripId)) {
            $statuses = $this->computationTracker->getStatuses($tripId) ?? [];
            $this->publisher->publishTripComplete($tripId, $statuses);
            // Mode 1 — terminal event: the frontend can swap the trip state atomically
            // once all computations have reported done/failed.
            $this->publishTripReady($tripId, $statuses);
        }
    }

    /**
     * Publishes the computation_step_completed progress event.
     *
     * The completed/total counts are derived from the ComputationTracker so a single
     * handler failure does not stall the progress bar (failed statuses still count
     * as "completed" from the user's perspective).
     */
    private function publishProgress(string $tripId, ComputationName $step): void
    {
        $statuses = $this->computationTracker->getStatuses($tripId);
        if (null === $statuses) {
            return;
        }

        $total = \count($statuses);
        $completed = \count(array_filter(
            $statuses,
            static fn (string $status): bool => 'done' === $status || 'failed' === $status,
        ));

        $this->publisher->publishComputationStepCompleted($tripId, $step, $completed, $total);
    }

    /**
     * Publishes the trip_ready terminal event with the fully enriched payload.
     *
     * The aggregated stage data is read back from the trip state repository
     * when available, so the single event carries everything the frontend
     * needs to render the full analysis without a layout shift.
     *
     * @param array<string, string> $statuses
     */
    private function publishTripReady(string $tripId, array $statuses): void
    {
        $stages = $this->tripRequestRepository->getStages($tripId) ?? [];
        $this->publisher->publishTripReady($tripId, $stages, [
            'status' => $statuses,
        ]);
    }
}
