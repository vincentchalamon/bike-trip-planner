<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripCompletionGate;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\Attribute\Required;

abstract readonly class AbstractTripMessageHandler
{
    protected TripCompletionGate $completionGate;

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
     * Injected via a setter rather than the constructor so the ~20 concrete
     * handlers extending this class do not have to thread the dependency through
     * their own constructors. The container calls this exactly once after
     * construction, which is a valid single write of the readonly property.
     */
    #[Required]
    public function setCompletionGate(TripCompletionGate $completionGate): void
    {
        $this->completionGate = $completionGate;
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
     *
     * On exception: publishes the error event and re-throws so Messenger can
     * retry. The computation is intentionally NOT marked `failed` here — that
     * would happen on every failed attempt, including ones a retry could still
     * recover, and would let the terminal gate fire prematurely. The terminal
     * `failed` status (and the gate re-evaluation) is set only once the retries
     * are exhausted, by {@see \App\EventListener\ComputationFailureSubscriber}.
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
        // (done OR failed), publish the terminal TRIP_COMPLETE event and dispatch
        // AllEnrichmentsCompleted. Shared with ComputationFailureSubscriber so the
        // happy path and the retries-exhausted path evaluate the same condition.
        $this->completionGate->evaluate($tripId);
    }

    /**
     * Publishes the computation_step_completed progress event and returns the progress snapshot.
     *
     * Counts are derived from the {@see ComputationTrackerInterface::getProgress()}
     * helper so a single handler failure does not stall the progress bar
     * (failed statuses still count toward the total settled steps).
     *
     * @return array{completed: int, failed: int, total: int}
     */
    private function publishProgress(string $tripId, ComputationName $step): array
    {
        $progress = $this->computationTracker->getProgress($tripId);

        if (0 !== $progress['total']) {
            $this->publisher->publishComputationStepCompleted(
                $tripId,
                $step,
                $progress['completed'],
                $progress['total'],
                $progress['failed'],
            );
        }

        return $progress;
    }
}
