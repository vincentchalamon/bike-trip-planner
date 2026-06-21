<?php

declare(strict_types=1);

namespace App\Service;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AllEnrichmentsCompleted;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Terminal gate of the enrichment pipeline (issue #299).
 *
 * Publishes the TRIP_COMPLETE Mercure event and dispatches the terminal
 * {@see AllEnrichmentsCompleted} message once every initialised computation has
 * settled (status `done` OR `failed`).
 *
 * Extracted from {@see \App\MessageHandler\AbstractTripMessageHandler} so the
 * gate can be re-evaluated from two places:
 *  - the happy path, right after a handler marks its computation `done`;
 *  - {@see \App\EventListener\ComputationFailureSubscriber}, once a handler's
 *    retries are exhausted and its computation is marked `failed`.
 *
 * Without the second trigger, a computation whose retries are exhausted would
 * stay stuck in `running` and the `completed + failed === total` condition would
 * never hold — leaving the frontend waiting for a terminal event that never
 * arrives (recette #649, Lot 1).
 *
 * Note: with 5 concurrent workers the check-and-dispatch is not atomic; two
 * workers can both observe the settled condition and both dispatch the message.
 * {@see \App\MessageHandler\AllEnrichmentsCompletedHandler} guards against
 * duplicate processing via {@see ComputationTrackerInterface::claimReadyPublication()}.
 */
final readonly class TripCompletionGate
{
    public function __construct(
        private ComputationTrackerInterface $computationTracker,
        private TripUpdatePublisherInterface $publisher,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Publishes the terminal event when every initialised computation has settled.
     */
    public function evaluate(string $tripId): void
    {
        $progress = $this->computationTracker->getProgress($tripId);

        $allSettled = $progress['total'] > 0
            && $progress['completed'] + $progress['failed'] === $progress['total'];

        if (!$allSettled) {
            return;
        }

        $statuses = $this->computationTracker->getStatuses($tripId) ?? [];
        $this->publisher->publishTripComplete($tripId, $statuses);
        $this->messageBus->dispatch(new AllEnrichmentsCompleted($tripId));
    }
}
