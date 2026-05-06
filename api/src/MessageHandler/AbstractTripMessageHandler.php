<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AllEnrichmentsCompleted;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

abstract readonly class AbstractTripMessageHandler
{
    public function __construct(
        protected ComputationTrackerInterface $computationTracker,
        protected TripUpdatePublisherInterface $publisher,
        protected TripGenerationTrackerInterface $generationTracker,
        protected LoggerInterface $logger,
        protected TripRequestRepositoryInterface $tripRequestRepository,
        protected MessageBusInterface $messageBus,
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

        // Issue #299 — gate: when every initialized enrichment has settled
        // (done OR failed), dispatch the terminal AllEnrichmentsCompleted message.
        // The dedicated handler decides whether to chain into LLaMA 8B (issues
        // #301-#303) or short-circuit by publishing TRIP_READY directly. This
        // keeps the gate triggered exactly once per pipeline run.
        if ($this->computationTracker->areAllEnrichmentsCompleted($tripId)) {
            $statuses = $this->computationTracker->getStatuses($tripId) ?? [];
            $this->publisher->publishTripComplete($tripId, $statuses);
            $this->messageBus->dispatch(new AllEnrichmentsCompleted($tripId));
        }
    }

    /**
     * Publishes the computation_step_completed progress event.
     *
     * Counts are derived from the {@see ComputationTrackerInterface::getProgress()}
     * helper so a single handler failure does not stall the progress bar
     * (failed statuses still count toward the total settled steps).
     */
    private function publishProgress(string $tripId, ComputationName $step): void
    {
        $progress = $this->computationTracker->getProgress($tripId);

        if (0 === $progress['total']) {
            return;
        }

        $this->publisher->publishComputationStepCompleted(
            $tripId,
            $step,
            $progress['completed'],
            $progress['total'],
            $progress['failed'],
        );
    }
}
