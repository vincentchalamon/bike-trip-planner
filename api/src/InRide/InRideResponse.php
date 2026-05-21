<?php

declare(strict_types=1);

namespace App\InRide;

/**
 * Response returned by {@see InRideAssistant::assist()}.
 *
 * Carries both the structured POI suggestions (so the UI can render maps,
 * deeplinks and badges) and the LLM-generated markdown narrative shown in the
 * chat bubble.
 *
 * `category` mirrors the intent detected by {@see PoiIntentDetector}. When the
 * intent is `unknown`, `pois` is empty and `narrative` contains an explanation.
 */
final readonly class InRideResponse
{
    /**
     * @param list<PoiSuggestion> $pois
     */
    public function __construct(
        public string $category,
        public array $pois,
        public string $narrative,
    ) {
    }
}
