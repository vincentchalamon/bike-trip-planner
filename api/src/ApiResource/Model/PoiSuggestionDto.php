<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;
use App\InRide\InRidePoiCategory;
use App\InRide\PoiSuggestion;
use App\InRide\PoiWarning;

/**
 * Wire-shape mirror of {@see PoiSuggestion}, built by {@see self::fromSuggestion()}.
 *
 * Exposing a typed DTO (rather than a raw `?array`) keeps the PHP → OpenAPI
 * → TypeScript contract honest: every field is documented, the generated
 * `schema.d.ts` carries the exact shape (including the category and warning
 * enums as TS unions), and the PWA Zod schema can rely on the typegen output
 * instead of duplicating the field list.
 *
 * Property names are intentionally snake_case to match the JSON the backend
 * produces — the PWA reads `distance_m`/`detour_m`/`opening_hours_today`/…
 * directly.
 */
final readonly class PoiSuggestionDto
{
    public function __construct(
        #[ApiProperty(description: 'Display name of the POI.', required: true)]
        public string $name,
        #[ApiProperty(description: 'POI intent category.', required: true)]
        public InRidePoiCategory $category,
        #[ApiProperty(description: 'POI latitude (WGS84).', required: true)]
        public float $lat,
        #[ApiProperty(description: 'POI longitude (WGS84).', required: true)]
        public float $lon,
        #[ApiProperty(description: 'Straight-line distance from the rider to the POI, in meters (rounded).', required: true)]
        public int $distance_m,
        #[ApiProperty(description: 'Estimated additional meters if the rider detours to the POI (null when no remaining route is known, rounded).')]
        public ?int $detour_m,
        #[ApiProperty(description: 'Raw OSM `opening_hours` tag for the current day, when available.')]
        public ?string $opening_hours_today,
        #[ApiProperty(description: 'RFC 3339 closing time of the currently-open interval, or null when the POI never closes / is closed.')]
        public ?string $closes_at,
        #[ApiProperty(description: 'Optional phone number extracted from the OSM tag.')]
        public ?string $phone,
        #[ApiProperty(description: 'Pre-built deeplink the rider can tap to open the POI in their map app.', required: true)]
        public string $deeplink,
        #[ApiProperty(description: 'Optional typed warning surfaced on the POI card (venue closes soon, POI far from route, opening hours unverified).')]
        public ?PoiWarning $warning,
        #[ApiProperty(description: 'Minutes left before closing when `warning` is `closes_soon`, otherwise null.')]
        public ?int $warning_minutes,
    ) {
    }

    public static function fromSuggestion(PoiSuggestion $suggestion): self
    {
        return new self(
            name: $suggestion->name,
            category: $suggestion->category,
            lat: $suggestion->lat,
            lon: $suggestion->lon,
            distance_m: (int) round($suggestion->distanceMeters),
            detour_m: null === $suggestion->detourMeters ? null : (int) round($suggestion->detourMeters),
            opening_hours_today: $suggestion->openingHoursToday,
            closes_at: $suggestion->closesAt?->format(\DateTimeInterface::ATOM),
            phone: $suggestion->phone,
            deeplink: $suggestion->deeplink,
            warning: $suggestion->warning,
            warning_minutes: $suggestion->warningMinutes,
        );
    }
}
