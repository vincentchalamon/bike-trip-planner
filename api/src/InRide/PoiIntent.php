<?php

declare(strict_types=1);

namespace App\InRide;

/**
 * Structured intent produced by {@see PoiIntentDetector} from a free-text in-ride
 * message.
 *
 * - `category`: one of {@see PoiSuggestion::SUPPORTED_CATEGORIES} or `unknown`.
 * - `maxDistanceMeters`: how far the rider is willing to detour (in meters).
 * - `openForMinutes`: optional temporal constraint — when set, only POIs that
 *   remain open for at least that many minutes from "now" are kept.
 */
final readonly class PoiIntent
{
    public function __construct(
        public string $category,
        public int $maxDistanceMeters,
        public ?int $openForMinutes = null,
    ) {
    }

    public static function unknown(): self
    {
        return new self(PoiSuggestion::CATEGORY_UNKNOWN, PoiIntentDetector::DEFAULT_RADIUS_METERS);
    }

    public function isUnknown(): bool
    {
        return PoiSuggestion::CATEGORY_UNKNOWN === $this->category;
    }
}
