<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use App\Enum\ComputationName;

/**
 * Tracks the lifecycle status of each async computation for a given trip.
 *
 * Statuses follow the lifecycle: pending → running → done | failed.
 */
interface ComputationTrackerInterface
{
    /** @param list<ComputationName> $computations */
    public function initializeComputations(string $tripId, array $computations): void;

    public function markRunning(string $tripId, ComputationName $computation): void;

    public function markDone(string $tripId, ComputationName $computation): void;

    public function markFailed(string $tripId, ComputationName $computation): void;

    public function resetComputation(string $tripId, ComputationName $computation): void;

    public function isAllComplete(string $tripId): bool;

    /**
     * Gate predicate: returns true when **every** initialized computation for the trip has settled.
     *
     * A settled computation is one whose terminal status is `done` or `failed`. This is the
     * canonical signal used by the {@see \App\MessageHandler\AbstractTripMessageHandler}
     * to decide whether to dispatch the `AllEnrichmentsCompleted` message that fires the
     * downstream LLaMA pipeline (or the immediate `TRIP_READY` event when LLaMA is disabled).
     *
     * Semantically equivalent to {@see isAllComplete()} but exposed under a name that
     * matches the gate vocabulary used elsewhere in the codebase (issue #299).
     */
    public function areAllEnrichmentsCompleted(string $tripId): bool;

    /**
     * Attempts to claim the "ready to publish" slot for the trip.
     *
     * Returns true on the first successful call — this worker owns the terminal
     * publication. Returns false when another worker already claimed the slot.
     *
     * Note: implemented via PSR-6 get/save; the TOCTOU window is sub-millisecond
     * (significantly safer than the unchecked gate). True atomic NX is tracked in #303.
     */
    public function claimReadyPublication(string $tripId): bool;

    /**
     * Returns the current progress counters for a trip's enrichments.
     *
     * @return array{completed: int, failed: int, total: int}
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
