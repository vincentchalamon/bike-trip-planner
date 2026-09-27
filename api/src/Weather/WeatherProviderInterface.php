<?php

declare(strict_types=1);

namespace App\Weather;

interface WeatherProviderInterface
{
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
