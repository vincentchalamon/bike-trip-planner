<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use App\Enum\ComputationName;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Keeps a durable copy of the enrichment status map, so it outlives the cache (ADR-072).
 *
 * The tracked state lives in Redis under a 30-minute TTL — shorter than the life of a trip.
 * Past it the state did not go cold, it ceased to exist anywhere, and both read paths fell
 * back to "there are stages, so it must be analysed": a trip whose every computation had
 * failed reported success. This is the same move that took the trip's version counter out of
 * Redis, for the same reason.
 *
 * A decorator rather than a change to {@see ComputationTracker}: the readers ask the
 * interface and get the fallback underneath, without knowing there is one. It also keeps the
 * cache implementation free of a database dependency.
 *
 * It stores through {@see ComputationStatusStore} rather than the trip repository interface,
 * which is aliased to the transient implementation in the `test` environment — through that
 * one, nothing would ever reach Postgres and the durability this class exists for would go
 * untested.
 */
#[AsDecorator(ComputationTracker::class)]
final readonly class PersistingComputationTracker implements ComputationTrackerInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ComputationTrackerInterface $inner,
        private ComputationStatusStore $trips,
    ) {
    }

    public function initializeComputations(string $tripId, array $computations): void
    {
        $this->inner->initializeComputations($tripId, $computations);
    }

    /**
     * Not mirrored: while a computation is running the cache is alive by construction, and
     * the durable copy only has to answer once it is gone.
     */
    public function markRunning(string $tripId, ComputationName $computation): void
    {
        $this->inner->markRunning($tripId, $computation);
    }

    public function markDone(string $tripId, ComputationName $computation): void
    {
        $this->inner->markDone($tripId, $computation);
        $this->mirror($tripId);
    }

    public function markFailed(string $tripId, ComputationName $computation): void
    {
        $this->inner->markFailed($tripId, $computation);
        $this->mirror($tripId);
    }

    public function resetComputation(string $tripId, ComputationName $computation): void
    {
        $this->inner->resetComputation($tripId, $computation);
    }

    public function claimReadyPublication(string $tripId): bool
    {
        return $this->inner->claimReadyPublication($tripId);
    }

    public function getProgress(string $tripId): array
    {
        return $this->inner->getProgress($tripId);
    }

    public function getStatuses(string $tripId): ?array
    {
        $statuses = $this->inner->getStatuses($tripId);
        if (null !== $statuses) {
            return $statuses;
        }

        $mirrored = $this->trips->getComputationStatus($tripId);

        // An empty mirror means the trip exists but nothing has settled yet, which is what
        // the cache would have said too: no computation is tracked.
        return null === $mirrored || [] === $mirrored ? null : $mirrored;
    }

    public function getStatusesBatch(array $tripIds): array
    {
        $statuses = $this->inner->getStatusesBatch($tripIds);

        $missing = array_values(array_filter(
            $tripIds,
            static fn (string $tripId): bool => null === ($statuses[$tripId] ?? null),
        ));

        if ([] === $missing) {
            return $statuses;
        }

        foreach ($this->trips->getComputationStatusBatch($missing) as $tripId => $mirrored) {
            if ([] !== $mirrored) {
                $statuses[$tripId] = $mirrored;
            }
        }

        return $statuses;
    }

    /**
     * Writes the whole map rather than the one entry that changed: the map is a handful of
     * short strings, and a full write leaves the column consistent whatever order the five
     * workers settle in.
     */
    private function mirror(string $tripId): void
    {
        $this->trips->storeComputationStatus($tripId, $this->inner->getStatuses($tripId) ?? []);
    }
}
