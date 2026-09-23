<?php

declare(strict_types=1);

namespace App\ComputationTracker;

/**
 * Where the enrichment status map is kept once the cache has let go of it (ADR-072).
 *
 * Narrow on purpose, and separate from `TripRequestRepositoryInterface`: that one expresses
 * none of these operations, and the merge below is a server-side jsonb `||` rather than a
 * read-modify-write. One interface, one implementation, no alias.
 */
interface ComputationStatusStore
{
    /**
     * Overwrites the whole map — a generation starting over, and nothing else.
     *
     * @param array<string, string> $statuses
     */
    public function replaceComputationStatus(string $tripId, array $statuses): void;

    /**
     * Writes one computation's status into the map, leaving the others alone.
     *
     * Five workers settle concurrently, so a read-then-overwrite of the whole map loses
     * whichever write was read first and landed last — silently, because while the cache is
     * alive nothing reads the mirror. This is one statement the database merges, so the order
     * the workers arrive in stops mattering.
     */
    public function mergeComputationStatus(string $tripId, string $computation, string $status): void;

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
