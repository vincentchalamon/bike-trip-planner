<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AllEnrichmentsCompleted;
use App\Notification\AnalysisNotifier;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Terminal handler of the enrichment pipeline (gate side).
 *
 * Fired by {@see AbstractTripMessageHandler} once the enrichment gate
 * (issue #299) detects that every initialised computation has settled
 * (`done` or `failed`). It publishes the `TRIP_READY` Mercure event directly
 * so the frontend swaps state atomically with the enriched payload, then asks the
 * {@see AnalysisNotifier} to push an `analysisDone` notification when no SSE client
 * is watching the trip (#1124).
 */
#[AsMessageHandler]
final readonly class AllEnrichmentsCompletedHandler
{
    public function __construct(
        private ComputationTrackerInterface $computationTracker,
        private TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripRequestRepository,
        private AnalysisNotifier $analysisNotifier,
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
        $counts = array_count_values($statuses);

        $this->logger->info('All enrichments completed for trip {tripId} ({completed} done, {failed} failed of {total}).', [
            'tripId' => $tripId,
            'completed' => $counts['done'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'total' => \count($statuses),
        ]);

        $stages = $this->tripRequestRepository->getStages($tripId) ?? [];

        $this->publisher->publishTripReady($tripId, $stages, [
            'status' => $statuses,
        ]);

        $this->analysisNotifier->notify($tripId, $statuses);
    }
}
