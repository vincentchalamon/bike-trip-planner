<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Llm\LlmAnalysisTrackerInterface;
use App\Llm\LlmClientInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AllEnrichmentsCompleted;
use App\Message\AnalyzeStageWithLlmMessage;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Terminal handler of the enrichment pipeline (gate side).
 *
 * Fired by {@see AbstractTripMessageHandler} once the enrichment gate
 * (issue #299) detects that every initialised computation has settled
 * (`done` or `failed`).
 *
 * From this point, two strategies coexist (issue #303 wires them):
 *
 *  - **Ollama disabled** (or no non-rest stage to analyse) — the handler is
 *    self-terminating: it publishes the `TRIP_READY` Mercure event directly
 *    so the frontend swaps state atomically with the enriched payload.
 *  - **Ollama enabled** — the handler initialises the dedicated LLM tracker
 *    ({@see LlmAnalysisTrackerInterface}) with the number of expected pass-1
 *    analyses, then dispatches one {@see AnalyzeStageWithLlmMessage} per
 *    non-rest stage. TRIP_READY is published later, by
 *    {@see AnalyzeTripOverviewWithLlmHandler}, once pass-2 has settled (or
 *    been skipped) — guaranteeing a single terminal event carrying both
 *    per-stage `aiAnalysis` and the trip-level `aiOverview`.
 *
 * The dual responsibility (publish-or-delegate) is deliberate: keeping it in
 * one place lets the frontend rely on a single TRIP_READY contract regardless
 * of the AI feature flag.
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
        private LlmAnalysisTrackerInterface $llmTracker,
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

        $analysableStages = $this->countAnalysableStages($stages);

        // Short-circuit: when the LLM is disabled OR there is no stage to analyse
        // (e.g. all rest days), publish TRIP_READY directly — no AI overview to await.
        if (!$this->llmClient->isEnabled() || 0 === $analysableStages) {
            $this->publisher->publishTripReady($tripId, $stages, [
                'status' => $statuses,
            ]);

            return;
        }

        // LLaMA 8B pass-1 dispatch: initialise the LLM tracker BEFORE dispatching
        // so concurrent pass-1 workers can reliably increment the counter.
        $this->llmTracker->initializeStageAnalyses($tripId, $analysableStages);

        // Surface the AI category in the progress bar (issue #303): emit a
        // single COMPUTATION_STEP_COMPLETED with completed=0 so the frontend
        // can register the new "Analyse IA" tracker before any pass-1 settles.
        $this->publisher->publishComputationStepCompleted(
            $tripId,
            ComputationName::STAGE_AI_ANALYSIS,
            completed: 0,
            total: $analysableStages + 1, // pass-1 stages + 1 pass-2 overview
            failed: 0,
        );

        foreach ($stages as $stage) {
            if ($stage->isRestDay) {
                continue;
            }

            $this->messageBus->dispatch(new AnalyzeStageWithLlmMessage($tripId, $stage->dayNumber));
        }

        // Defensive corner case: when there is exactly one non-rest stage and that
        // stage's pass-1 hits an empty repository or LLM error, the tracker will
        // still settle from the worker side. We therefore do NOT short-circuit
        // pass-2 here — the LlmAnalysisTracker handles the all-failed path.
    }

    /**
     * Counts the stages that are eligible for pass-1 LLaMA analysis (non-rest).
     *
     * @param list<Stage> $stages
     */
    private function countAnalysableStages(array $stages): int
    {
        $count = 0;
        foreach ($stages as $stage) {
            if (!$stage->isRestDay) {
                ++$count;
            }
        }

        return $count;
    }
}
