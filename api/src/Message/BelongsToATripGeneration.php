<?php

declare(strict_types=1);

namespace App\Message;

/**
 * A message that was built against one generation of a trip, and is worthless once the trip
 * has moved past it (ADR-073).
 *
 * The generation is the trip's structural version at dispatch time
 * ({@see \App\ComputationTracker\TripGenerationTracker}); `null` means the sender did not
 * scope the message to one, and it is then never discarded.
 *
 * Declared rather than inferred: {@see \App\Messenger\StaleMessageMiddleware} used to be three
 * hand-written comparisons living inside the handlers, and the project's other way of reaching
 * these fields is a `@var object{tripId: string}` cast. An interface is what lets the guard
 * apply to every message without each one opting in by accident, and what lets
 * {@see \App\Tests\Unit\MessengerRoutingTest} refuse a new trip-scoped message that forgot it.
 */
interface BelongsToATripGeneration
{
    public string $tripId { get; }

    public ?int $generation { get; }
}
