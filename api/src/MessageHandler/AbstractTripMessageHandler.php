<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;

abstract readonly class AbstractTripMessageHandler
{
    public function __construct(
        protected ComputationTrackerInterface $computationTracker,
        protected TripUpdatePublisherInterface $publisher,
    ) {
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
    ): void {
        $this->computationTracker->markRunning($tripId, $computation);

        try {
            $callback();
            $this->computationTracker->markDone($tripId, $computation);
        } catch (\Throwable $throwable) {
            $this->computationTracker->markFailed($tripId, $computation);
            $this->publisher->publishComputationError($tripId, $computation->value, $throwable->getMessage());

            throw $throwable;
        }

        if ($this->computationTracker->isAllComplete($tripId)) {
            $statuses = $this->computationTracker->getStatuses($tripId) ?? [];
            $this->publisher->publishTripComplete($tripId, $statuses);
        }
    }
}
