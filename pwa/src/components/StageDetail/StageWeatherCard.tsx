"use client";

import { useTranslations } from "next-intl";
import { Sunrise, Sunset, Droplets, Wind, Gauge } from "lucide-react";
import { weatherIconMap, DefaultWeatherIcon } from "@/lib/weather-icons";
import {
  computeSunTimes,
  computeStageDate,
  formatSunTime,
} from "@/lib/sun-times";
import type { WeatherData } from "@/lib/validation/schemas";

function getComfortColor(index: number): string {
  if (index >= 70) return "text-emerald-500";
  if (index >= 40) return "text-amber-500";
  return "text-red-500";
}

interface StageWeatherCardProps {
  weather: WeatherData | null;
  /** Trip start date (used to derive sunrise/sunset for the right calendar day). */
  startDate?: string | null;
  /** 0-based stage index, combined with `startDate` to locate the calendar day. */
  stageIndex: number;
  /** End-of-stage coordinates (used as the reference point for sunrise/sunset). */
  endPointLat?: number;
  endPointLon?: number;
}

/**
 * Enriched weather card for the right-hand stage detail panel.
 *
 * Shows the daily forecast (icon, description, temperature range) alongside
 * wind, humidity, precipitation probability and a comfort index. Sunrise and
 * sunset times for the stage end point — when the trip start date is set —
 * are surfaced inline as a compact "daylight" footer so riders can plan
 * around shoulder hours.
 *
 * NOTE: hourly forecast data is not yet exposed by the backend. This card is
 * forward-compatible — when an `hourlyForecast` field becomes available it
 * can be slotted in above the daylight footer without altering the existing
 * markup.
 */
export function StageWeatherCard({
  weather,
  startDate,
  stageIndex,
  endPointLat,
  endPointLon,
}: StageWeatherCardProps) {
  const t = useTranslations("weather");

  const sunTimes =
    endPointLat !== undefined && endPointLon !== undefined
      ? (() => {
          const stageDate = computeStageDate(startDate ?? null, stageIndex);
          if (!stageDate) return null;
          return computeSunTimes(stageDate, endPointLat, endPointLon);
        })()
      : null;

  const showSunTimes =
    sunTimes && sunTimes.sunrise !== null && sunTimes.sunset !== null;

  if (!weather && !showSunTimes) {
    return null;
  }

  const Icon = weather
    ? (weatherIconMap[weather.icon] ?? DefaultWeatherIcon)
    : null;

  const relativeWindLabel =
    weather && weather.relativeWindDirection !== "unknown"
      ? t(
          `relativeWind_${weather.relativeWindDirection}` as
            | "relativeWind_headwind"
            | "relativeWind_tailwind"
            | "relativeWind_crosswind",
        )
      : (weather?.windDirection ?? "");

  return (
    <section
      data-testid="stage-weather-card"
      aria-label={t("cardAriaLabel")}
      className="rounded-lg border border-border bg-card/40 p-3"
    >
      {weather && Icon && (
        <div className="flex flex-wrap items-start justify-between gap-3">
          {/* Left — icon + description + temperature range */}
          <div className="flex items-start gap-3 min-w-0">
            <div
              aria-hidden="true"
              className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-foreground/80"
            >
              <Icon className="h-5 w-5" />
            </div>
            <div className="min-w-0">
              <p className="text-sm font-medium text-foreground">
                {/* Kept as a single string ("description, min-max°C") to stay
                    compatible with the long-running E2E assertions in
                    `alerts-weather.spec.ts` that match the substring directly. */}
                {weather.description}, {Math.round(weather.tempMin)}-
                {Math.round(weather.tempMax)}°C
              </p>
            </div>
          </div>

          {/* Right — comfort index pill */}
          <div
            className={`inline-flex items-center gap-1 text-xs font-medium ${getComfortColor(weather.comfortIndex)}`}
            title={`${t("comfortIndex")}: ${weather.comfortIndex}/100`}
            data-testid="stage-weather-comfort"
          >
            <Gauge className="h-3.5 w-3.5" aria-hidden="true" />
            <span>{weather.comfortIndex}/100</span>
          </div>
        </div>
      )}

      {weather && (
        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
          <div
            className="flex items-center gap-1"
            title={`${t("wind")}: ${weather.windDirection}`}
          >
            <Wind className="h-3.5 w-3.5" aria-hidden="true" />
            <span>
              {Math.round(weather.windSpeed)} km/h {relativeWindLabel}
            </span>
          </div>

          <div className="flex items-center gap-1" title={t("humidity")}>
            <Droplets className="h-3.5 w-3.5" aria-hidden="true" />
            <span>{weather.humidity}%</span>
          </div>

          {weather.precipitationProbability > 0 && (
            <div className="flex items-center gap-1" title={t("rain")}>
              <span className="text-blue-400" aria-hidden="true">
                🌧
              </span>
              <span>{weather.precipitationProbability}%</span>
            </div>
          )}
        </div>
      )}

      {showSunTimes && (
        <div
          className={`flex items-center justify-between gap-3 text-xs text-muted-foreground${weather ? " mt-3 border-t border-border/60 pt-2" : ""}`}
          data-testid="stage-weather-sun-times"
          title={t("sunriseSunsetTooltip")}
        >
          <div className="flex items-center gap-1.5">
            <Sunrise className="h-4 w-4 text-amber-400" aria-hidden="true" />
            <span className="tabular-nums">
              {formatSunTime(sunTimes.sunrise)}
            </span>
            <span className="text-muted-foreground/70">
              {t("sunriseShort")}
            </span>
          </div>
          <div className="flex items-center gap-1.5">
            <span className="text-muted-foreground/70">{t("sunsetShort")}</span>
            <span className="tabular-nums">
              {formatSunTime(sunTimes.sunset)}
            </span>
            <Sunset className="h-4 w-4 text-orange-400" aria-hidden="true" />
          </div>
        </div>
      )}
    </section>
  );
}
