<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

/**
 * One hour of the stage's riding window, in the stage location's local time.
 *
 * `relativeWindDirection` is pre-computed backend-side against the stage bearing
 * (headwind/tailwind/crosswind/unknown) so the frontend can orient the wind
 * arrow without knowing the bearing.
 */
final readonly class HourlyWeatherSlot
{
    public function __construct(
        public int $hour,
        public float $temp,
        public float $apparentTemp,
        public float $precipitationMm,
        public int $precipitationProbability,
        public float $windSpeed,
        public float $windGusts,
        public int $windDirectionDeg,
        public string $relativeWindDirection,
        public int $weatherCode,
    ) {
    }
}
