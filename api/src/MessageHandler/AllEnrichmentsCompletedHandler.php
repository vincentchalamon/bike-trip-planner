<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AllEnrichmentsCompleted;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Terminal handler of the enrichment pipeline.
 *
 * This handler is fired exactly once per trip by {@see AbstractTripMessageHandler}
 * when the gate in `ComputationTracker::areAllEnrichmentsCompleted()` flips to true.
 *
 * The full design (issues #301-#303) is to chain this handler into the LLaMA 8B
 * narrative analysis. Until that pipeline exists, the handler implements a minimal
 * fallback: it publishes the `TRIP_READY` Mercure event directly so the frontend
 * receives the terminal signal and can render the fully enriched trip atomically.
 *
 * Once LLaMA is wired in, this handler can dispatch the `RunLlamaAnalysis` message
 * and let *that* downstream handler publish `TRIP_READY` after the AI overview
 * has been computed — without breaking any consumer of `TRIP_READY`.
 */
#[AsMessageHandler]
final readonly class AllEnrichmentsCompletedHandler
{
    public function __construct(
        private ComputationTrackerInterface $computationTracker,
        private TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripRequestRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AllEnrichmentsCompleted $message): void
    {
        $tripId = $message->tripId;

        if (!$this->computationTracker->claimReadyPublication($tripId)) {
            $this->logger->info('AllEnrichmentsCompleted already handled for trip {tripId} — skipping duplicate.', [
                'tripId' => $tripId,
            ]);

            return;
        }

        $statuses = $this->computationTracker->getStatuses($tripId) ?? [];
        $progress = $this->computationTracker->getProgress($tripId);

        $this->logger->info('All enrichments completed for trip {tripId} ({completed} done, {failed} failed of {total}).', [
            'tripId' => $tripId,
            'completed' => $progress['completed'],
            'failed' => $progress['failed'],
            'total' => $progress['total'],
        ]);

        // TODO(#301-#303): when the LLaMA 8B pipeline lands, dispatch the analysis
        // message here instead of publishing TRIP_READY directly. The downstream
        // handler will then own the publication of TRIP_READY with the AI overview.
        $stages = $this->tripRequestRepository->getStages($tripId) ?? [];
        $this->publisher->publishTripReady($tripId, $stages, [
            'status' => $statuses,
        ]);
    }
}
