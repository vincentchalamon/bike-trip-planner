<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\Llm\LlmClientInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AllEnrichmentsCompleted;
use App\Message\AnalyzeStageWithLlmMessage;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Terminal handler of the enrichment pipeline.
 *
 * This handler is fired by {@see AbstractTripMessageHandler} when the enrichment gate
 * (progress arithmetic) detects that every computation has settled.
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
        private MessageBusInterface $messageBus,
        private LlmClientInterface $llmClient,
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

        // Issue #301 — LLaMA 8B pass 1: dispatch one AnalyzeStageWithLlmMessage per non-rest
        // stage. Messages are independent and parallelisable across the worker pool. The
        // handler is no-op when Ollama is disabled, so dispatching costs nothing in that case.
        // TRIP_READY is still published synchronously here: the AI analysis is best-effort
        // enrichment that lands later via a dedicated Mercure event (issues #302-#303).
        if ($this->llmClient->isEnabled()) {
            foreach ($stages as $stage) {
                if ($stage->isRestDay) {
                    continue;
                }

                $this->messageBus->dispatch(new AnalyzeStageWithLlmMessage($tripId, $stage->dayNumber));
            }
        }

        $this->publisher->publishTripReady($tripId, $stages, [
            'status' => $statuses,
        ]);
    }
}
