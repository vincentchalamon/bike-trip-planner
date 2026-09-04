<?php

declare(strict_types=1);

namespace App\Weather;

interface WeatherProviderInterface
{
    /**
     * Returns null when the provider has no usable forecast for the location (a
     * failed call after retries, or a response missing the core fields) — never a
     * fabricated default, so the caller leaves the stage weather genuinely absent.
     */
    public function fetchForecast(float $lat, float $lon, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): ?RawForecast;

    /**
     * Fetch raw hourly series for multiple locations in a single API call, over
     * the [startDate, endDate] range (inclusive, provider forecast horizon). The
     * result is aligned to $locations by index; an entry is null when that
     * location has no usable forecast.
     *
     * @param list<array{lat: float, lon: float}> $locations
     *
     * @return list<?RawForecast>
     */
    public function fetchForecasts(array $locations, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): array;
}
