<?php

declare(strict_types=1);

namespace App\Weather;

use App\ApiResource\Model\WeatherForecast;

interface WeatherProviderInterface
{
    /**
     * Returns null when the provider has no usable forecast for the location (a
     * failed call after retries, or a response missing the core fields) — never a
     * fabricated default, so the caller leaves the stage weather genuinely absent.
     */
    public function fetchForecast(float $lat, float $lon, string $locale = 'en'): ?WeatherForecast;

    /**
     * Fetch forecasts for multiple locations in a single API call. The result is
     * aligned to $locations by index; an entry is null when that location has no
     * usable forecast.
     *
     * @param list<array{lat: float, lon: float}> $locations
     *
     * @return list<?WeatherForecast>
     */
    public function fetchForecasts(array $locations, string $locale = 'en'): array;
}
