<?php

declare(strict_types=1);

namespace App\Weather;

/**
 * One raw hourly reading straight from the provider, in the location's local
 * time. Trip-agnostic: the handler slices these by stage date + riding window
 * and derives the exposed forecast from them.
 */
final readonly class RawHourlySlot
{
    public function __construct(
        public \DateTimeImmutable $time,
        public float $temp,
        public float $apparentTemp,
        public float $precipitationMm,
        public int $precipitationProbability,
        public float $windSpeed,
        public float $windGusts,
        public int $windDirectionDeg,
        public int $humidity,
        public float $uvIndex,
        public int $weatherCode,
    ) {
    }
}
