<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Wire-shape mirror of {@see \App\InRide\PoiSuggestion::toArray()}.
 *
 * Exposing a typed DTO (rather than a raw `?array`) keeps the PHP → OpenAPI
 * → TypeScript contract honest: every field is documented, the generated
 * `schema.d.ts` carries the exact shape, and the PWA Zod schema can rely on
 * the typegen output instead of duplicating the field list.
 *
 * Property names are intentionally snake_case to match the JSON the backend
 * produces — `PoiSuggestion::toArray()` is the canonical serialiser and the
 * PWA reads `distance_m`/`detour_m`/`opening_hours_today`/… directly.
 */
final readonly class PoiSuggestionDto
{
    public function __construct(
        #[ApiProperty(description: 'Display name of the POI.', required: true)]
        public string $name,
        #[ApiProperty(description: 'POI category (food, water, shelter, mechanic).', required: true)]
        public string $category,
        #[ApiProperty(description: 'POI latitude (WGS84).', required: true)]
        public float $lat,
        #[ApiProperty(description: 'POI longitude (WGS84).', required: true)]
        public float $lon,
        #[ApiProperty(description: 'Straight-line distance from the rider to the POI, in meters (rounded).', required: true)]
        public int $distance_m,
        #[ApiProperty(description: 'Estimated additional meters if the rider detours to the POI (0 when no remaining route is known, rounded).', required: true)]
        public int $detour_m,
        #[ApiProperty(description: 'Raw OSM `opening_hours` tag for the current day, when available.')]
        public ?string $opening_hours_today,
        #[ApiProperty(description: 'RFC 3339 closing time of the currently-open interval, or null when the POI never closes / is closed.')]
        public ?string $closes_at,
        #[ApiProperty(description: 'Optional phone number extracted from the OSM tag.')]
        public ?string $phone,
        #[ApiProperty(description: 'Pre-built deeplink the rider can tap to open the POI in their map app.', required: true)]
        public string $deeplink,
        #[ApiProperty(description: 'Optional warning surfaced on the POI card (e.g. opening hours unreliable, POI far from route).')]
        public ?string $warning,
    ) {
    }

    /**
     * @param array{
     *     name: string,
     *     category: string,
     *     lat: float,
     *     lon: float,
     *     distance_m: int,
     *     detour_m: int,
     *     opening_hours_today: ?string,
     *     closes_at: ?string,
     *     phone: ?string,
     *     deeplink: string,
     *     warning: ?string,
     * } $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            name: $payload['name'],
            category: $payload['category'],
            lat: $payload['lat'],
            lon: $payload['lon'],
            distance_m: $payload['distance_m'],
            detour_m: $payload['detour_m'],
            opening_hours_today: $payload['opening_hours_today'],
            closes_at: $payload['closes_at'],
            phone: $payload['phone'],
            deeplink: $payload['deeplink'],
            warning: $payload['warning'],
        );
    }
}
