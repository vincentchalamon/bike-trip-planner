<?php

declare(strict_types=1);

namespace App\ComputationTracker;

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
     */
    public function increment(string $tripId): int;

    /**
     * Returns the current generation, or null if the trip is unknown.
     */
    public function current(string $tripId): ?int;
}
