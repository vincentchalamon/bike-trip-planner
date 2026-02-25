<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

final readonly class WeatherForecast
{
    public function __construct(
        public string $icon,
        public string $description,
        public float $tempMin,
        public float $tempMax,
        public float $windSpeed,
        public string $windDirection,
        public int $precipitationProbability,
    ) {
    }
}
