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
final readonly class AllEnrichmentsCompleted implements BelongsToATripGeneration
{
    /**
     * @param ?int $generation Which generation settled. It was the one pipeline message without
     *                         one, which had two consequences: the terminal event could not be
     *                         discarded when the trip had moved past it, and the publication
     *                         claim could not be scoped to a generation — so no generation
     *                         after the first ever published a second `trip_ready` (ADR-073).
     */
    public function __construct(
        public string $tripId,
        public ?int $generation = null,
    ) {
    }
}
