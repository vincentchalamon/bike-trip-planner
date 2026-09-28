<?php

declare(strict_types=1);

namespace App\Mercure;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;

/**
 * Publishes the `computation_step_completed` progress event once a step has settled.
 *
 * Counts come from {@see ComputationTrackerInterface::getProgress()}, so a failed step still
 * counts toward the settled total and one failure does not stall the progress bar. A trip with
 * no tracked computation publishes nothing.
 *
 * Shared by the workers ({@see \App\MessageHandler\AbstractTripMessageHandler}) and the GPX
 * upload, which settles its structural steps inside the request.
 */
final readonly class ProgressPublisher
{
    public function __construct(
        private ComputationTrackerInterface $computationTracker,
        private TripUpdatePublisherInterface $publisher,
    ) {
    }

    public function publish(string $tripId, ComputationName $step): void
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
