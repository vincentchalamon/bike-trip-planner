<?php

declare(strict_types=1);

namespace App\Weather;

use App\ApiResource\Model\HourlyWeatherSlot;
use App\ApiResource\Model\WeatherForecast;

/**
 * Turns a location's raw hourly series into the stage-level WeatherForecast for
 * the actual riding window [startHour, endHour] on the stage's local date.
 *
 * Trip-aware but pure: same raw series + same (date, window, bearing) always
 * yields the same forecast, so the raw series can be cached location-wide and
 * re-derived cheaply when the rider changes pace or departure time.
 */
final readonly class WeatherForecastDeriver
{
    public function __construct(
        private WmoWeatherMapper $wmoMapper,
        private RelativeWindCalculator $relativeWindCalculator = new RelativeWindCalculator(),
        private ComfortIndexCalculator $comfortIndexCalculator = new ComfortIndexCalculator(),
    ) {
    }

    /**
     * @param float|null $stageBearing bearing start→end, or null for a trivial/rest stage
     */
    public function derive(
        RawForecast $raw,
        string $localDate,
        float $startHour,
        float $endHour,
        ?float $stageBearing,
        string $locale,
    ): ?WeatherForecast {
        // Absolute [start, end] window anchored at the stage's local midnight, so a
        // riding window that crosses midnight (late departure / very long stage)
        // still picks up the post-midnight hours from the next day's slots. Hours
        // are then expressed relative to that midnight (0..23, then 24, 25… past
        // midnight) so they stay monotonic for the graph; the UI shows `hour % 24`.
        $midnight = new \DateTimeImmutable($localDate.' 00:00:00', $raw->timezone);
        $startTs = $midnight->getTimestamp() + (int) floor($startHour) * 3600;
        $endTs = $midnight->getTimestamp() + (int) ceil($endHour) * 3600;
        $hourOf = static fn (RawHourlySlot $s): int => (int) round(($s->time->getTimestamp() - $midnight->getTimestamp()) / 3600);

        $window = array_values(array_filter(
            $raw->slots,
            static fn (RawHourlySlot $s): bool => $s->time->getTimestamp() >= $startTs && $s->time->getTimestamp() <= $endTs,
        ));

        // No hour of the riding window is covered by the forecast: no fake data.
        if ([] === $window) {
            return null;
        }

        $temps = array_map(static fn (RawHourlySlot $s): float => $s->temp, $window);
        $apparents = array_map(static fn (RawHourlySlot $s): float => $s->apparentTemp, $window);
        $windSpeeds = array_map(static fn (RawHourlySlot $s): float => $s->windSpeed, $window);
        $gusts = array_map(static fn (RawHourlySlot $s): float => $s->windGusts, $window);
        $precipProbs = array_map(static fn (RawHourlySlot $s): int => $s->precipitationProbability, $window);
        $uvs = array_map(static fn (RawHourlySlot $s): float => $s->uvIndex, $window);

        $tempMax = max($temps);
        $windMax = max($windSpeeds);
        $precipProbMax = max($precipProbs);
        $precipSum = array_sum(array_map(static fn (RawHourlySlot $s): float => $s->precipitationMm, $window));

        // Representative (median-hour) slot for the headline icon/description/wind.
        $mid = $window[intdiv(\count($window), 2)];
        $humidity = $mid->humidity;

        $windDirection = $this->wmoMapper->degToDirection($mid->windDirectionDeg);
        $relativeHeadline = null !== $stageBearing
            ? $this->relativeWindCalculator->classify($windDirection, $stageBearing)
            : WeatherForecast::RELATIVE_WIND_UNKNOWN;

        $hourly = array_map(
            fn (RawHourlySlot $s): HourlyWeatherSlot => new HourlyWeatherSlot(
                hour: $hourOf($s),
                temp: round($s->temp, 1),
                apparentTemp: round($s->apparentTemp, 1),
                precipitationMm: round($s->precipitationMm, 1),
                precipitationProbability: $s->precipitationProbability,
                windSpeed: round($s->windSpeed, 1),
                windGusts: round($s->windGusts, 1),
                windDirectionDeg: $s->windDirectionDeg,
                relativeWindDirection: null !== $stageBearing
                    ? $this->relativeWindCalculator->classify($this->wmoMapper->degToDirection($s->windDirectionDeg), $stageBearing)
                    : WeatherForecast::RELATIVE_WIND_UNKNOWN,
                weatherCode: $s->weatherCode,
            ),
            $window,
        );

        return new WeatherForecast(
            icon: $this->wmoMapper->toIcon($mid->weatherCode),
            description: $this->wmoMapper->toDescription($mid->weatherCode, $locale),
            tempMin: round(min($temps), 1),
            tempMax: round($tempMax, 1),
            windSpeed: round($windMax, 1),
            windDirection: $windDirection,
            precipitationProbability: $precipProbMax,
            humidity: $humidity,
            comfortIndex: $this->comfortIndexCalculator->compute($tempMax, $windMax, $humidity, $precipProbMax),
            relativeWindDirection: $relativeHeadline,
            apparentTempMin: round(min($apparents), 1),
            apparentTempMax: round(max($apparents), 1),
            windGusts: round(max($gusts), 1),
            precipitationMm: round($precipSum, 1),
            uvIndex: (int) round(max($uvs)),
            hourly: $hourly,
        );
    }
}
