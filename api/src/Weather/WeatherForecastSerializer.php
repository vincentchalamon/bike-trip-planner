<?php

declare(strict_types=1);

namespace App\Weather;

use App\ApiResource\Model\HourlyWeatherSlot;
use App\ApiResource\Model\WeatherForecast;

/**
 * Single source for the wire shape of a forecast, shared by the Mercure payload,
 * the stage payload mapper and the trip detail provider so the schema stays in
 * lockstep with `core/` (WeatherForecastSchema / WeatherPayload).
 */
final readonly class WeatherForecastSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(WeatherForecast $w): array
    {
        return [
            'icon' => $w->icon,
            'description' => $w->description,
            'tempMin' => $w->tempMin,
            'tempMax' => $w->tempMax,
            'windSpeed' => round($w->windSpeed, 1),
            'windDirection' => $w->windDirection,
            'precipitationProbability' => $w->precipitationProbability,
            'humidity' => $w->humidity,
            'comfortIndex' => $w->comfortIndex,
            'relativeWindDirection' => $w->relativeWindDirection,
            'apparentTempMin' => $w->apparentTempMin,
            'apparentTempMax' => $w->apparentTempMax,
            'windGusts' => round($w->windGusts, 1),
            'precipitationMm' => $w->precipitationMm,
            'uvIndex' => $w->uvIndex,
            'hourly' => array_map(
                static fn (HourlyWeatherSlot $s): array => [
                    'hour' => $s->hour,
                    'temp' => $s->temp,
                    'apparentTemp' => $s->apparentTemp,
                    'precipitationMm' => $s->precipitationMm,
                    'precipitationProbability' => $s->precipitationProbability,
                    'windSpeed' => $s->windSpeed,
                    'windGusts' => $s->windGusts,
                    'windDirectionDeg' => $s->windDirectionDeg,
                    'relativeWindDirection' => $s->relativeWindDirection,
                    'weatherCode' => $s->weatherCode,
                ],
                $w->hourly,
            ),
        ];
    }
}
