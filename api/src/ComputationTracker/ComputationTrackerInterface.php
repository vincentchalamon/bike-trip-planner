<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use App\Enum\ComputationName;

/**
 * Tracks the lifecycle status of each async computation for a given trip.
 *
 * Statuses follow the lifecycle: pending → running → done | failed | superseded.
 *
 * `superseded` is terminal like the other two, and means the trip moved past this computation
 * before it settled — nothing failed, and nothing will be retried (ADR-073).
 */
interface ComputationTrackerInterface
{
    /** @param list<ComputationName> $computations */
    public function initializeComputations(string $tripId, array $computations): void;

    public function markRunning(string $tripId, ComputationName $computation): void;

    public function markDone(string $tripId, ComputationName $computation): void;

    public function markFailed(string $tripId, ComputationName $computation): void;

    /**
     * Records that the trip moved past this computation before it settled.
     *
     * Only takes effect while the computation is still `pending` or `running`: the caller runs
     * after a generation bump, and the newer generation may already have settled the same
     * computation. Overwriting a `done` would report abandoned work that in fact succeeded
     * (ADR-073).
     *
     * Returns true when the status was actually written.
     */
    public function markSupersededUnlessSettled(string $tripId, ComputationName $computation): bool;

    public function resetComputation(string $tripId, ComputationName $computation): void;

    /**
     * Attempts to claim the "ready to publish" slot for a generation of the trip.
     *
     * Returns true on the first successful call — this worker owns the terminal
     * publication. Returns false when another worker already claimed the slot.
     *
     * Scoped to the generation: the claim used to be per trip and was never cleared, so once
     * one generation had published `trip_ready`, no later generation ever published another
     * (ADR-073).
     *
     * Note: implemented via PSR-6 get/save; the TOCTOU window is sub-millisecond
     * (significantly safer than the unchecked gate). True atomic NX is tracked in #303.
     */
    public function claimReadyPublication(string $tripId, ?int $generation = null): bool;

    /**
     * Returns the current progress counters for a trip's enrichments.
     *
     * `settled` counts every terminal status — done, failed and superseded — and is what the
     * completion gate compares against `total`. `completed` and `failed` stay as they were:
     * the progress payload the frontend renders distinguishes success from failure, and a
     * superseded computation is neither.
     *
     * @return array{completed: int, failed: int, settled: int, total: int}
     */
    public function getProgress(string $tripId): array;

    /** @return array<string, string>|null */
    public function getStatuses(string $tripId): ?array;

    /**
     * Batch-fetches the status maps of several trips in a single cache round-trip.
     *
     * Returns an array keyed by `$tripId`. Trips with no tracked computations
     * are mapped to `null`, matching the shape of {@see getStatuses()}.
     *
     * @param list<string> $tripIds
     *
     * @return array<string, array<string, string>|null>
     */
    public function getStatusesBatch(array $tripIds): array;
}
