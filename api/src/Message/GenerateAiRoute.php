<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async request to generate a routed itinerary from a natural-language brief
 * (B1, ADR-042). Dispatched by {@see \App\State\TripAiGenerateProcessor} and
 * handled by {@see \App\MessageHandler\GenerateAiRouteHandler}.
 *
 * The brief is plain user text; the provider token is NEVER carried here — the
 * handler resolves the trip owner's client at processing time.
 */
final readonly class GenerateAiRoute
{
    public function __construct(
        public string $tripId,
        public string $brief,
        public string $locale,
        public ?int $generation = null,
    ) {
    }
}
