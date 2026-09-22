<?php

declare(strict_types=1);

namespace App\Service;

use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AllEnrichmentsCompleted;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Terminal gate of the enrichment pipeline (issue #299).
 *
 * Publishes the TRIP_COMPLETE Mercure event and dispatches the terminal
 * {@see AllEnrichmentsCompleted} message once every initialised computation has
 * settled — `done`, `failed`, or `superseded` since ADR-073. Before that third one existed,
 * a single message the trip had moved past left its computation `pending` for good and this
 * condition could never hold again.
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
 * Note: with concurrent workers the check-and-dispatch is not atomic; two
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
        private TripGenerationTrackerInterface $generationTracker,
    ) {
    }

    /**
     * Publishes the terminal event when every initialised computation has settled.
     */
    public function evaluate(string $tripId): void
    {
        $progress = $this->computationTracker->getProgress($tripId);

        if (0 === $progress['total'] || $progress['settled'] !== $progress['total']) {
            return;
        }

        $statuses = $this->computationTracker->getStatuses($tripId) ?? [];
        $this->publisher->publishTripComplete($tripId, $statuses);

        // Which generation settled, so the publication can be claimed per generation rather
        // than once per trip. Read here rather than threaded in: if an edit lands between this
        // read and the handler, the message is of the older generation and the middleware
        // discards it — which is the right answer, the newer generation will settle in turn.
        $this->messageBus->dispatch(
            new AllEnrichmentsCompleted($tripId, $this->generationTracker->current($tripId)),
        );
    }
}
