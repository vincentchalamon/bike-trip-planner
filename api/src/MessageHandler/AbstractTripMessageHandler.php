<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use Psr\Log\LoggerInterface;

abstract readonly class AbstractTripMessageHandler
{
    public function __construct(
        protected ComputationTrackerInterface $computationTracker,
        protected TripUpdatePublisherInterface $publisher,
        protected TripGenerationTrackerInterface $generationTracker,
        protected LoggerInterface $logger,
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
            $this->computationTracker->markDone($tripId, $computation);
            $this->logger->info('Handler {name} completed in {duration}ms.', [
                'name' => $computation->value,
                'duration' => (int) ((hrtime(true) - $startTime) / 1_000_000),
                'tripId' => $tripId,
            ]);
        } catch (\Throwable $throwable) {
            $this->computationTracker->markFailed($tripId, $computation);
            $this->publisher->publishComputationError($tripId, $computation->value, $throwable->getMessage());
            $this->logger->warning('Handler {name} failed after {duration}ms.', [
                'name' => $computation->value,
                'duration' => (int) ((hrtime(true) - $startTime) / 1_000_000),
                'tripId' => $tripId,
            ]);

            throw $throwable;
        }

        if ($this->computationTracker->isAllComplete($tripId)) {
            $statuses = $this->computationTracker->getStatuses($tripId) ?? [];
            $this->publisher->publishTripComplete($tripId, $statuses);
        }
    }
}
