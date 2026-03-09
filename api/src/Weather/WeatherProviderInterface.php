<?php

declare(strict_types=1);

namespace App\Weather;

use App\ApiResource\Model\WeatherForecast;

interface WeatherProviderInterface
{
    public function fetchForecast(float $lat, float $lon, string $locale = 'en'): WeatherForecast;

    /**
     * Fetch forecasts for multiple locations in a single API call.
     *
     * @param list<array{lat: float, lon: float}> $locations
     *
     * @return list<WeatherForecast>
     */
    public function fetchForecasts(array $locations, string $locale = 'en'): array;
}
