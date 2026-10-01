<?php

declare(strict_types=1);

namespace App\Weather;

interface WeatherProviderInterface
{
    /**
     * Fetch, for each location, the raw hourly series of its local `date` and of the day
     * after (a riding window crossing midnight reads its early hours there), in as few API
     * calls as the provider allows. The result is aligned to $locations by index; an entry is
     * null when that location has no usable forecast for the day.
     *
     * @param list<array{lat: float, lon: float, date: string}> $locations `date` as Y-m-d
     *
     * @return list<?RawForecast>
     */
    public function fetchDayForecasts(array $locations): array;
}
