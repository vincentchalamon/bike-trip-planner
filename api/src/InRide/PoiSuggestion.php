<?php

declare(strict_types=1);

namespace App\InRide;

/**
 * Single POI suggestion served to a cycling user mid-ride.
 *
 * Distances are expressed in meters. `detourMeters` is the additional distance
 * compared to staying on the route (see {@see DetourCalculator}); it is `null`
 * when no remaining route was available to measure against, never a misleading
 * `0`.
 *
 * `openingHoursToday` is the raw OSM `opening_hours` tag — the
 * {@see OpeningHoursParser} has already produced a tri-state verdict from it.
 * `closesAt` is the moment the venue closes for the in-progress open interval.
 *
 * `warning` is a typed {@see PoiWarning}; `warningMinutes` carries the minutes
 * left before closing when `warning` is {@see PoiWarning::CLOSES_SOON}.
 * `osmType`/`osmId` link the suggestion back to the Tier-1 index row and to
 * openstreetmap.org.
 */
final readonly class PoiSuggestion
{
    public function __construct(
        public string $name,
        public InRidePoiCategory $category,
        public string $osmType,
        public int $osmId,
        public float $lat,
        public float $lon,
        public float $distanceMeters,
        public ?float $detourMeters,
        public ?string $openingHoursToday,
        public ?\DateTimeImmutable $closesAt,
        public ?string $phone,
        public string $deeplink,
        public ?PoiWarning $warning = null,
        public ?int $warningMinutes = null,
    ) {
    }
}
