"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import {
  Sunrise,
  Sunset,
  Droplets,
  Wind,
  Gauge,
  Moon,
  Sun,
  Thermometer,
  ChevronDown,
  ChevronUp,
} from "lucide-react";
import { weatherIconMap, DefaultWeatherIcon } from "@/lib/weather-icons";
import {
  computeSunTimes,
  computeStageDate,
  formatSunTime,
} from "@/lib/sun-times";
import type { WeatherData } from "@btp/core";
import { StageWeatherStrip } from "./StageWeatherStrip";
import { StageWeatherProfile } from "./StageWeatherProfile";

/** Open-Meteo forecast horizon; beyond it the backend returns no weather. */
const FORECAST_HORIZON_DAYS = 16;

/** Gusts are only worth surfacing on the card when notably above the mean wind. */
const GUST_HIGHLIGHT_DELTA_KMH = 15;

function getComfortColor(index: number): string {
  if (index >= 70) return "text-emerald-500";
  if (index >= 40) return "text-amber-500";
  return "text-red-500";
}

/**
 * Returns whether the sun is currently up at the stage's reference point, or
 * `null` when the comparison is not meaningful (the stage is not today, or
 * sunrise/sunset are unavailable for the location/date — polar day/night).
 *
 * `sunrise` / `sunset` are decimal UTC hours — same convention as
 * `computeSunTimes`. `now` defaults to the current wall clock; injecting it is
 * useful in unit tests.
 */
export function isSunUp(
  stageDate: Date | null,
  sunrise: number | null,
  sunset: number | null,
  now: Date = new Date(),
): boolean | null {
  if (!stageDate || sunrise === null || sunset === null) return null;
  // Only show a "live" indicator when the stage date is today (UTC) — the
  // ride is happening now, not in the future or past.
  const today = new Date(
    Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()),
  );
  const stageDay = new Date(
    Date.UTC(
      stageDate.getUTCFullYear(),
      stageDate.getUTCMonth(),
      stageDate.getUTCDate(),
    ),
  );
  if (today.getTime() !== stageDay.getTime()) return null;

  const nowDecimal =
    now.getUTCHours() + now.getUTCMinutes() / 60 + now.getUTCSeconds() / 3600;
  return nowDecimal >= sunrise && nowDecimal < sunset;
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

  const stageDate =
    endPointLat !== undefined && endPointLon !== undefined
      ? computeStageDate(startDate ?? null, stageIndex)
      : null;
  const sunTimes =
    stageDate && endPointLat !== undefined && endPointLon !== undefined
      ? computeSunTimes(stageDate, endPointLat, endPointLon)
      : null;

  const showSunTimes =
    sunTimes && sunTimes.sunrise !== null && sunTimes.sunset !== null;

  // `null` when the stage isn't today — only render the live badge then.
  const sunIsUp = sunTimes
    ? isSunUp(stageDate, sunTimes.sunrise, sunTimes.sunset)
    : null;

  const [detailOpen, setDetailOpen] = useState(false);
  const hasHourly = (weather?.hourly?.length ?? 0) > 0;

  // Beyond the provider horizon the backend returns no weather; tell the rider
  // why rather than showing an empty card. Computed in an effect since it reads
  // the wall clock (impure during render).
  const calendarTime =
    computeStageDate(startDate ?? null, stageIndex)?.getTime() ?? null;
  const [beyondHorizon, setBeyondHorizon] = useState(false);
  useEffect(() => {
    setBeyondHorizon(
      !weather &&
        calendarTime !== null &&
        (calendarTime - Date.now()) / 86_400_000 > FORECAST_HORIZON_DAYS,
    );
  }, [weather, calendarTime]);

  if (!weather && !showSunTimes && !beyondHorizon) {
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
          {hasHourly && (
            <div className="flex items-center gap-1" title={t("feelsLike")}>
              <Thermometer className="h-3.5 w-3.5" aria-hidden="true" />
              <span>
                {t("feelsLike")} {Math.round(weather.apparentTempMin)}-
                {Math.round(weather.apparentTempMax)}°
              </span>
            </div>
          )}

          <div
            className="flex items-center gap-1"
            title={`${t("wind")}: ${weather.windDirection}`}
          >
            <Wind className="h-3.5 w-3.5" aria-hidden="true" />
            <span>
              {Math.round(weather.windSpeed)} km/h {relativeWindLabel}
              {hasHourly &&
              weather.windGusts >= weather.windSpeed + GUST_HIGHLIGHT_DELTA_KMH
                ? ` · ${t("gusts")} ${Math.round(weather.windGusts)}`
                : ""}
            </span>
          </div>

          <div className="flex items-center gap-1" title={t("humidity")}>
            <Droplets className="h-3.5 w-3.5" aria-hidden="true" />
            <span>{weather.humidity}%</span>
          </div>

          {(hasHourly
            ? weather.precipitationMm > 0
            : weather.precipitationProbability > 0) && (
            <div className="flex items-center gap-1" title={t("rain")}>
              <span className="text-blue-400" aria-hidden="true">
                🌧
              </span>
              <span>
                {hasHourly
                  ? `${weather.precipitationMm} ${t("mmUnit")}`
                  : `${weather.precipitationProbability}%`}
              </span>
            </div>
          )}
        </div>
      )}

      {hasHourly && weather && (
        <>
          <StageWeatherStrip
            hourly={weather.hourly}
            onSelectHour={() => setDetailOpen(true)}
          />

          <button
            type="button"
            aria-expanded={detailOpen}
            aria-controls="stage-weather-detail"
            onClick={() => setDetailOpen((v) => !v)}
            data-testid="stage-weather-detail-toggle"
            className="mt-2 inline-flex min-h-11 items-center gap-1 text-xs font-medium text-foreground/80 hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
          >
            {detailOpen ? (
              <ChevronUp className="h-4 w-4" aria-hidden="true" />
            ) : (
              <ChevronDown className="h-4 w-4" aria-hidden="true" />
            )}
            {t("hourlyDetailToggle")}
          </button>

          {detailOpen && (
            <div id="stage-weather-detail" className="mt-2">
              <StageWeatherProfile hourly={weather.hourly} />
            </div>
          )}
        </>
      )}

      {beyondHorizon && (
        <p
          data-testid="stage-weather-horizon"
          className="text-xs text-muted-foreground"
        >
          {t("forecastHorizon", { days: FORECAST_HORIZON_DAYS })}
        </p>
      )}

      {showSunTimes && (
        <div
          className={`flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground${weather ? " mt-3 border-t border-border/60 pt-2" : ""}`}
          data-testid="stage-weather-sun-times"
          title={t("sunriseSunsetTooltip")}
        >
          <div className="flex items-center gap-1.5">
            <Sunrise className="h-4 w-4 text-amber-400" aria-hidden="true" />
            <span className="tabular-nums">
              {formatSunTime(sunTimes.sunrise)}
            </span>
            <span className="text-muted-foreground">{t("sunriseShort")}</span>
          </div>

          {sunIsUp !== null && (
            <span
              data-testid="stage-weather-sun-state"
              data-state={sunIsUp ? "day" : "night"}
              className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${
                sunIsUp
                  ? "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
                  : "bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300"
              }`}
            >
              {sunIsUp ? (
                <Sun className="h-3 w-3" aria-hidden="true" />
              ) : (
                <Moon className="h-3 w-3" aria-hidden="true" />
              )}
              {sunIsUp ? t("sunStateDay") : t("sunStateNight")}
            </span>
          )}

          <div className="flex items-center gap-1.5">
            <span className="text-muted-foreground">{t("sunsetShort")}</span>
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
