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

    /** @return array<string, string>|null */
    public function getStatuses(string $tripId): ?array;
}
