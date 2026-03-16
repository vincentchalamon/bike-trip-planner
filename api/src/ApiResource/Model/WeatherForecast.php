<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

final readonly class WeatherForecast
{
    /** Wind direction relative to the cycling direction: coming from the front. */
    public const string RELATIVE_WIND_HEADWIND = 'headwind';

    /** Wind direction relative to the cycling direction: coming from behind. */
    public const string RELATIVE_WIND_TAILWIND = 'tailwind';

    /** Wind direction relative to the cycling direction: coming from the side. */
    public const string RELATIVE_WIND_CROSSWIND = 'crosswind';

    /** No stage bearing available to compute relative wind direction. */
    public const string RELATIVE_WIND_UNKNOWN = 'unknown';

    public function __construct(
        public string $icon,
        public string $description,
        public float $tempMin,
        public float $tempMax,
        public float $windSpeed,
        public string $windDirection,
        public int $precipitationProbability,
        public int $humidity,
        public int $comfortIndex,
        public string $relativeWindDirection,
    ) {
    }
}
