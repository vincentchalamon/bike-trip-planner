"use client";

import { useTranslations } from "next-intl";
import { Droplets, Wind, Gauge } from "lucide-react";
import { weatherIconMap, DefaultWeatherIcon } from "@/lib/weather-icons";
import type { WeatherData } from "@/lib/validation/schemas";

interface WeatherIndicatorProps {
  weather: WeatherData | null;
}

function getComfortColor(index: number): string {
  if (index >= 70) return "text-green-500";
  if (index >= 40) return "text-yellow-500";
  return "text-red-500";
}

export function WeatherIndicator({ weather }: WeatherIndicatorProps) {
  const t = useTranslations("weather");

  if (!weather) return null;

  const Icon = weatherIconMap[weather.icon] ?? DefaultWeatherIcon;

  const relativeWindLabel =
    weather.relativeWindDirection !== "unknown"
      ? t(
          `relativeWind_${weather.relativeWindDirection}` as
            | "relativeWind_headwind"
            | "relativeWind_tailwind"
            | "relativeWind_crosswind",
        )
      : weather.windDirection;

  return (
    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
      {/* Icon + description + temperature */}
      <div className="flex items-center gap-1.5">
        <Icon className="h-4 w-4" />
        <span>
          {weather.description}, {Math.round(weather.tempMin)}-
          {Math.round(weather.tempMax)}°C
        </span>
      </div>

      {/* Wind speed + relative direction */}
      <div
        className="flex items-center gap-1"
        title={`${t("wind")}: ${weather.windDirection}`}
      >
        <Wind className="h-3.5 w-3.5" />
        <span>
          {Math.round(weather.windSpeed)} km/h {relativeWindLabel}
        </span>
      </div>

      {/* Humidity */}
      <div className="flex items-center gap-1" title={t("humidity")}>
        <Droplets className="h-3.5 w-3.5" />
        <span>{weather.humidity}%</span>
      </div>

      {/* Rain probability */}
      {weather.precipitationProbability > 0 && (
        <div className="flex items-center gap-1" title={t("rain")}>
          <span className="text-blue-400">🌧</span>
          <span>{weather.precipitationProbability}%</span>
        </div>
      )}

      {/* Comfort index */}
      <div
        className={`flex items-center gap-1 ${getComfortColor(weather.comfortIndex)}`}
        title={`${t("comfortIndex")}: ${weather.comfortIndex}/100`}
      >
        <Gauge className="h-3.5 w-3.5" />
        <span>{weather.comfortIndex}/100</span>
      </div>
    </div>
  );
}
