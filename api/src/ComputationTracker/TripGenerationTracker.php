<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use App\Repository\TripRequestRepositoryInterface;

/**
 * The generation a message is stamped with is the trip's structural version.
 *
 * It used to be a Redis counter of its own, which had three defects that fed each other:
 * `increment()` was a non-atomic get/+1/set, so two concurrent edits could hand out the
 * same generation; the key expired after 30 minutes, after which the counter restarted
 * from 1 and could therefore go *backwards* while messages were still in flight; and a
 * missing key reads as "not stale" in {@see \App\MessageHandler\AbstractTripMessageHandler::isStale()},
 * so past that TTL the guard stopped rejecting anything at all (#252, RC1 and RC5).
 *
 * Reading it from the persisted trip version fixes all three at once: it is bumped inside
 * the write transaction, it never expires, and it never decreases. It also means a
 * regeneration performed by a worker moves it, which a counter only the HTTP processors
 * incremented never did.
 */
final readonly class TripGenerationTracker implements TripGenerationTrackerInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    /**
     * No-op: a trip row is created at version 1, so the counter exists from the start.
     * Kept so callers do not have to know that.
     */
    public function initialize(string $tripId): void
    {
    }

    public function increment(string $tripId): int
    {
        return $this->tripStateManager->bumpVersion($tripId);
    }

    public function current(string $tripId): ?int
    {
        return $this->tripStateManager->getVersion($tripId);
    }
}
