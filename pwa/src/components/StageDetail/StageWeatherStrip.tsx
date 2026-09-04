"use client";

import { useTranslations } from "next-intl";
import { weatherCodeToConditionKey, type HourlyWeatherData } from "@btp/core";
import { weatherIconForCode } from "@/lib/weather-icons";

interface StageWeatherStripProps {
  hourly: HourlyWeatherData[];
  /** Optional: open the detailed graph focused on the tapped hour. */
  onSelectHour?: (hour: number) => void;
}

/**
 * Always-visible hourly conditions strip (icon + temperature per riding hour).
 * The familiar weather-app pattern: legible small and accessible per cell, so it
 * gives the "shape of the day" at a glance without the density of a chart.
 */
export function StageWeatherStrip({
  hourly,
  onSelectHour,
}: StageWeatherStripProps) {
  const t = useTranslations("weather");

  if (hourly.length === 0) return null;

  const cellLabel = (slot: HourlyWeatherData): string => {
    const condition = t(
      `condition.${weatherCodeToConditionKey(slot.weatherCode)}`,
    );
    const wind =
      slot.relativeWindDirection !== "unknown"
        ? `, ${t(`relativeWind_${slot.relativeWindDirection}` as "relativeWind_headwind" | "relativeWind_tailwind" | "relativeWind_crosswind")}`
        : "";
    return `${slot.hour}h, ${condition}, ${Math.round(slot.temp)}°C, ${t("wind")} ${Math.round(slot.windSpeed)} km/h${wind}`;
  };

  return (
    <ul
      role="list"
      aria-label={t("hourlyStripAriaLabel")}
      data-testid="stage-weather-strip"
      className="mt-3 flex gap-1 overflow-x-auto pb-1"
    >
      {hourly.map((slot) => {
        const Icon = weatherIconForCode(slot.weatherCode);
        const cell = (
          <>
            <span className="tabular-nums text-muted-foreground">
              {slot.hour}h
            </span>
            <Icon className="h-4 w-4 text-foreground/80" aria-hidden="true" />
            <span className="tabular-nums font-medium">
              {Math.round(slot.temp)}°
            </span>
          </>
        );

        return (
          <li key={slot.hour} role="listitem" className="shrink-0">
            {onSelectHour ? (
              <button
                type="button"
                onClick={() => onSelectHour(slot.hour)}
                aria-label={cellLabel(slot)}
                className="flex min-h-11 min-w-11 flex-col items-center gap-0.5 rounded-md px-2 py-1 text-[11px] hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
              >
                {cell}
              </button>
            ) : (
              <div
                aria-label={cellLabel(slot)}
                className="flex min-h-11 min-w-11 flex-col items-center gap-0.5 px-2 py-1 text-[11px]"
              >
                {cell}
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}
