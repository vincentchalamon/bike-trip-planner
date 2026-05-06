<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched once the gate in {@see \App\ComputationTracker\ComputationTracker}
 * detects that every initialized enrichment for the trip has settled.
 *
 * Triggers the downstream LLaMA 8B analysis pipeline (issues #301-#303). While that
 * pipeline does not exist yet, the handler short-circuits and publishes the terminal
 * `TRIP_READY` Mercure event directly so the frontend can swap state atomically.
 */
final readonly class AllEnrichmentsCompleted
{
    public function __construct(
        public string $tripId,
    ) {
    }
}
