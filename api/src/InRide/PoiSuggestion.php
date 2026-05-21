<?php

declare(strict_types=1);

namespace App\InRide;

/**
 * Single POI suggestion served by {@see InRideAssistant} to a cycling user mid-ride.
 *
 * Distances are expressed in meters. Detour is the additional distance compared
 * to staying on the route (see {@see DetourCalculator}).
 *
 * `openingHoursToday` is the raw OSM `opening_hours` tag — the {@see OpeningHoursParser}
 * has already validated it for the current day. `closesAt` is the moment the venue
 * closes for the in-progress open interval.
 *
 * `warning` carries human-readable diagnostics (e.g. "closes soon", "far from route").
 */
final readonly class PoiSuggestion
{
    public const string CATEGORY_FOOD = 'food';

    public const string CATEGORY_SHELTER = 'shelter';

    public const string CATEGORY_WATER = 'water';

    public const string CATEGORY_MECHANIC = 'mechanic';

    public const string CATEGORY_UNKNOWN = 'unknown';

    public const array SUPPORTED_CATEGORIES = [
        self::CATEGORY_FOOD,
        self::CATEGORY_SHELTER,
        self::CATEGORY_WATER,
        self::CATEGORY_MECHANIC,
    ];

    public function __construct(
        public string $name,
        public string $category,
        public float $lat,
        public float $lon,
        public float $distanceMeters,
        public float $detourMeters,
        public ?string $openingHoursToday,
        public ?\DateTimeImmutable $closesAt,
        public ?string $phone,
        public string $deeplink,
        public ?string $warning = null,
    ) {
    }

    /**
     * @return array{name: string, category: string, lat: float, lon: float, distance_m: int, detour_m: int, opening_hours_today: string|null, closes_at: string|null, phone: string|null, deeplink: string, warning: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'lat' => $this->lat,
            'lon' => $this->lon,
            'distance_m' => (int) round($this->distanceMeters),
            'detour_m' => (int) round($this->detourMeters),
            'opening_hours_today' => $this->openingHoursToday,
            'closes_at' => $this->closesAt?->format(\DateTimeInterface::ATOM),
            'phone' => $this->phone,
            'deeplink' => $this->deeplink,
            'warning' => $this->warning,
        ];
    }
}
