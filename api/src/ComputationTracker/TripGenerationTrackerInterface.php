<?php

declare(strict_types=1);

namespace App\ComputationTracker;

use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Tracks a monotonically increasing generation counter per trip.
 *
 * When trip criteria change (e.g. fatigue factor, start date), the generation
 * is incremented. Each dispatched message carries the generation at dispatch
 * time. Workers compare the message generation against the current value —
 * if they differ, the message is stale and can be discarded without processing.
 */
interface TripGenerationTrackerInterface
{
    /**
     * Sets the generation counter to 1 for a new trip.
     */
    public function initialize(string $tripId): void;

    /**
     * Atomically increments and returns the new generation.
     *
     * $expectedVersion carries a client's `If-Match` precondition for the two operations
     * that bump the version without rewriting the stage collection (trip settings, batch
     * recompute). It is compared inside the write's critical section, never before it —
     * see {@see \App\Repository\TripRequestRepositoryInterface::bumpVersion()}. Every other
     * caller, workers included, passes nothing and is unaffected.
     *
     * @throws PreconditionFailedHttpException when $expectedVersion is stale
     */
    public function increment(string $tripId, ?int $expectedVersion = null): int;

    /**
     * Returns the current generation, or null if the trip is unknown.
     */
    public function current(string $tripId): ?int;
}
