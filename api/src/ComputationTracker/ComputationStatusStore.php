<?php

declare(strict_types=1);

namespace App\ComputationTracker;

/**
 * Where the enrichment status map is kept once the cache has let go of it (ADR-072).
 *
 * Narrow on purpose, and separate from `TripRequestRepositoryInterface`: that one is aliased
 * to the transient implementation in the `test` environment, so depending on it here would
 * mean the durability this exists for went untested. One interface, one implementation, no
 * alias.
 */
interface ComputationStatusStore
{
    /** @param array<string, string> $statuses */
    public function storeComputationStatus(string $tripId, array $statuses): void;

    /**
     * The mirrored map, or null when the trip is unknown.
     *
     * An empty map is not null: it means the trip exists and nothing has settled yet.
     *
     * @return array<string, string>|null
     */
    public function getComputationStatus(string $tripId): ?array;

    /**
     * @param list<string> $tripIds
     *
     * @return array<string, array<string, string>>
     */
    public function getComputationStatusBatch(array $tripIds): array;
}
