<?php

declare(strict_types=1);

namespace App\Weather;

use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\Enum\AlertCode;

/**
 * The per-stage weather rules, one case each, in the order their alerts are emitted.
 *
 * A table rather than a tagged service per rule: each rule is a threshold on one field of
 * the stage forecast, and only the headwind one looks beyond its own stage.
 *
 * Every rule offers the same "got it" dismiss button. Its label key is `alert.wind.action`
 * for all six because headwind was the first rule to have one; the label is generic, so a
 * per-rule key would only duplicate the catalogue entry.
 */
enum WeatherStageRule
{
    case HEADWIND;
    case POOR_COMFORT;
    case HEAT;
    case COLD;
    case HEAVY_RAIN;
    case STRONG_GUSTS;

    public const float WIND_SPEED_THRESHOLD_KMH = 25.0;

    /** Share of the forecast stages that must carry a headwind for any of them to be flagged. */
    public const float HEADWIND_RATIO_THRESHOLD = 0.6;

    public const int COMFORT_INDEX_POOR_THRESHOLD = 39;

    /** Apparent temperature at or above this (°C) flags a heat-risk stage. */
    public const float HEAT_APPARENT_MAX_C = 32.0;

    /** Apparent temperature at or below this (°C) flags a cold-risk stage. */
    public const float COLD_APPARENT_MIN_C = 2.0;

    /** Total precipitation over the riding window at or above this (mm) flags heavy rain. */
    public const float RAIN_HEAVY_MM = 10.0;

    /** Wind gusts at or above this (km/h) flag a strong-gust stage. */
    public const float WIND_GUSTS_STRONG_KMH = 50.0;

    public const string ACTION_LABEL_KEY = 'alert.wind.action';

    public function code(): AlertCode
    {
        return match ($this) {
            self::HEADWIND => AlertCode::WIND_HEADWIND,
            self::POOR_COMFORT => AlertCode::COMFORT_POOR_CONDITIONS,
            self::HEAT => AlertCode::HEAT_EXTREME,
            self::COLD => AlertCode::COLD_EXTREME,
            self::HEAVY_RAIN => AlertCode::RAIN_HEAVY,
            self::STRONG_GUSTS => AlertCode::WIND_GUSTS_STRONG,
        };
    }

    public function messageKey(): string
    {
        return match ($this) {
            self::HEADWIND => 'alert.wind.stage',
            self::POOR_COMFORT => 'alert.comfort.stage',
            self::HEAT => 'alert.heat.stage',
            self::COLD => 'alert.cold.stage',
            self::HEAVY_RAIN => 'alert.rain.stage',
            self::STRONG_GUSTS => 'alert.gusts.stage',
        };
    }

    /**
     * @return array<string, float>
     */
    public function parameters(): array
    {
        return match ($this) {
            self::HEADWIND => ['%threshold%' => self::WIND_SPEED_THRESHOLD_KMH],
            self::POOR_COMFORT => [],
            self::HEAT => ['%threshold%' => self::HEAT_APPARENT_MAX_C],
            self::COLD => ['%threshold%' => self::COLD_APPARENT_MIN_C],
            self::HEAVY_RAIN => ['%threshold%' => self::RAIN_HEAVY_MM],
            self::STRONG_GUSTS => ['%threshold%' => self::WIND_GUSTS_STRONG_KMH],
        };
    }

    /**
     * The stages this rule flags, in trip order.
     *
     * The headwind rule stays trip-wide in what triggers it — it is about a trip spent riding
     * into the wind, not one windy day — so its stages are flagged only when they make up
     * enough of the forecast ones. The alert still sits on each such stage (ADR-066).
     *
     * @param list<Stage> $stages
     *
     * @return list<Stage>
     */
    public function stagesRaising(array $stages): array
    {
        $forecast = array_values(array_filter($stages, static fn (Stage $stage): bool => $stage->weather instanceof WeatherForecast));
        $raising = array_values(array_filter($forecast, fn (Stage $stage): bool => $this->matches($stage->weather)));

        if (self::HEADWIND === $this && [] !== $forecast && \count($raising) / \count($forecast) < self::HEADWIND_RATIO_THRESHOLD) {
            return [];
        }

        return $raising;
    }

    private function matches(?WeatherForecast $weather): bool
    {
        if (!$weather instanceof WeatherForecast) {
            return false;
        }

        // The apparent-temperature / rain-mm / gust thresholds are only meaningful once the
        // hourly derivation has populated those fields; legacy or partial forecasts carry
        // defaults there.
        $derived = [] !== $weather->hourly;

        return match ($this) {
            self::HEADWIND => $weather->windSpeed >= self::WIND_SPEED_THRESHOLD_KMH
                && WeatherForecast::RELATIVE_WIND_HEADWIND === $weather->relativeWindDirection,
            self::POOR_COMFORT => $weather->comfortIndex <= self::COMFORT_INDEX_POOR_THRESHOLD,
            self::HEAT => $derived && $weather->apparentTempMax >= self::HEAT_APPARENT_MAX_C,
            self::COLD => $derived && $weather->apparentTempMin <= self::COLD_APPARENT_MIN_C,
            self::HEAVY_RAIN => $derived && $weather->precipitationMm >= self::RAIN_HEAVY_MM,
            self::STRONG_GUSTS => $derived && $weather->windGusts >= self::WIND_GUSTS_STRONG_KMH,
        };
    }
}
