<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ComputationStatus;
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

        // Claimed per generation: the claim used to be one key per trip that nothing ever
        // cleared, so the first generation to get here silenced every generation after it and
        // an edited trip never announced itself ready again (ADR-073).
        if (!$this->computationTracker->claimReadyPublication($tripId, $message->generation)) {
            $this->logger->info('AllEnrichmentsCompleted already handled for trip {tripId} — skipping duplicate.', [
                'tripId' => $tripId,
                'generation' => $message->generation,
            ]);

            return;
        }

        $statuses = $this->computationTracker->getStatuses($tripId) ?? [];
        $counts = array_count_values($statuses);

        $this->logger->info('All enrichments completed for trip {tripId} ({completed} done, {failed} failed, {superseded} superseded of {total}).', [
            'tripId' => $tripId,
            'completed' => $counts[ComputationStatus::DONE->value] ?? 0,
            'failed' => $counts[ComputationStatus::FAILED->value] ?? 0,
            'superseded' => $counts[ComputationStatus::SUPERSEDED->value] ?? 0,
            'total' => \count($statuses),
        ]);

        $stages = $this->tripRequestRepository->getStages($tripId) ?? [];

        $this->publisher->publishTripReady($tripId, $stages, [
            'status' => $statuses,
        ]);

        $this->analysisNotifier->notify($tripId, $statuses);
    }
}
