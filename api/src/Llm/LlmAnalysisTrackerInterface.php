<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Tracks the progress of the LLaMA 8B analysis pipeline (issue #303).
 *
 * Distinct from {@see \App\ComputationTracker\ComputationTrackerInterface} because:
 *  - it is initialised AFTER the main enrichment gate has fired,
 *  - settling its counter must NOT re-trigger the gate (otherwise TRIP_READY
 *    would be published twice — once before, once after pass-2),
 *  - pass-1 and pass-2 are sequential phases with their own readiness gates.
 */
interface LlmAnalysisTrackerInterface
{
    /**
     * Initialises the tracker for a trip with the expected number of pass-1
     * stage analyses. A value of 0 short-circuits the pipeline — the caller
     * should treat that case as "no AI analysis to run".
     */
    public function initializeStageAnalyses(string $tripId, int $expectedStages): void;

    /**
     * Marks one pass-1 stage analysis as settled (succeeded or failed).
     *
     * Returns the snapshot of the counter AFTER incrementing so the caller
     * can decide whether to dispatch pass-2.
     *
     * @return array{completed: int, failed: int, total: int}
     */
    public function markStageAnalysisSettled(string $tripId, bool $success): array;

    /**
     * Returns the current snapshot of the pass-1 counter, or null if the
     * tracker has not been initialised for this trip (e.g. LLM disabled).
     *
     * @return array{completed: int, failed: int, total: int}|null
     */
    public function getStageAnalysisProgress(string $tripId): ?array;

    /**
     * Atomic NX-style claim of the "pass-2 dispatched" slot.
     *
     * Returns true on the first successful call — the caller owns the dispatch.
     * Subsequent calls return false to guard against duplicate triggers when
     * several pass-1 workers settle concurrently.
     */
    public function claimOverviewDispatch(string $tripId): bool;

    /**
     * Atomic NX-style claim of the "TRIP_READY published" slot for the LLM
     * pipeline. Mirrors {@see \App\ComputationTracker\ComputationTrackerInterface::claimReadyPublication}
     * so the trip-overview handler can publish exactly once even if it is
     * retried by the messenger.
     */
    public function claimTripReadyPublication(string $tripId): bool;

    /**
     * Marks the next pending enrichment cycle as "skip LLaMA 8B re-analysis".
     *
     * Used by chat-driven inline edits (issue #311): when the rider acts on the
     * trip via the AI bubble, the recomputation pipeline reruns the geographic
     * scans but the AI overview should NOT be invalidated by default. The flag
     * is consumed by the gate handler so it only impacts the very next cycle.
     */
    public function markSkipAiAnalysis(string $tripId): void;

    /**
     * Atomically reads and clears the "skip LLaMA 8B re-analysis" marker.
     *
     * Returns true when the marker was set (and is now consumed), false otherwise.
     */
    public function consumeSkipAiAnalysis(string $tripId): bool;
}
