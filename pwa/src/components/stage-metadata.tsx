"use client";

import {
  ArrowUp,
  ArrowDown,
  Bike,
  Mountain,
  Clock,
  Sunrise,
  Sunset,
} from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { WeatherIndicator } from "@/components/weather-indicator";
import type { WeatherData } from "@/lib/validation/schemas";
import { computeStageTimes, formatDecimalHour } from "@/lib/travel-time";
import {
  computeSunTimes,
  computeStageDate,
  formatSunTime,
} from "@/lib/sun-times";
import { useTranslations } from "next-intl";

interface StageMetadataProps {
  distance: number | null;
  elevation: number | null;
  elevationLoss: number | null;
  weather: WeatherData | null;
  isProcessing?: boolean;
  departureHour?: number;
  averageSpeedKmh?: number;
  endPointLat?: number;
  endPointLon?: number;
  startDate?: string | null;
  stageIndex?: number;
}

export function StageMetadata({
  distance,
  elevation,
  elevationLoss,
  weather,
  isProcessing,
  departureHour,
  averageSpeedKmh,
  endPointLat,
  endPointLon,
  startDate,
  stageIndex,
}: StageMetadataProps) {
  const t = useTranslations("stage");

  const travelTime =
    departureHour !== undefined &&
    averageSpeedKmh !== undefined &&
    distance !== null &&
    distance > 0
      ? computeStageTimes(
          departureHour,
          distance,
          averageSpeedKmh,
          elevation ?? 0,
        )
      : null;

  const sunTimes =
    endPointLat !== undefined &&
    endPointLon !== undefined &&
    startDate !== undefined &&
    stageIndex !== undefined
      ? (() => {
          const stageDate = computeStageDate(startDate ?? null, stageIndex);
          if (!stageDate) return null;
          return computeSunTimes(stageDate, endPointLat, endPointLon);
        })()
      : null;

  return (
    <div className="flex items-center gap-4 text-sm text-muted-foreground flex-wrap">
      <div className="flex items-center gap-1.5">
        <Bike className="h-4 w-4 text-brand" />
        {distance !== null ? (
          <span>{Math.round(distance)}km</span>
        ) : (
          <Skeleton className="w-12 h-4" />
        )}
      </div>
      <div className="flex items-center gap-1.5">
        <Mountain className="h-4 w-4 text-orange-500" />
        {elevation !== null ? (
          <span className="flex items-center gap-1">
            <ArrowUp className="h-3 w-3 text-red-500" />
            {Math.round(elevation)}m
            <ArrowDown className="h-3 w-3 text-blue-500" />
            {Math.round(elevationLoss ?? 0)}m
          </span>
        ) : (
          <Skeleton className="w-20 h-4" />
        )}
      </div>
      {travelTime && (
        <div
          className="flex items-center gap-1.5"
          title={t("travelTimeTooltip")}
        >
          <Clock className="h-4 w-4 text-indigo-500" />
          <span>
            {t("travelTime", {
              departure: formatDecimalHour(travelTime.departureDecimal),
              arrival: formatDecimalHour(travelTime.arrivalDecimal),
            })}
          </span>
        </div>
      )}
      {sunTimes && sunTimes.sunrise !== null && sunTimes.sunset !== null && (
        <div
          className="flex items-center gap-1.5"
          title={t("sunriseSunsetTooltip")}
        >
          <Sunrise className="h-4 w-4 text-amber-400" />
          <span>{formatSunTime(sunTimes.sunrise)}</span>
          <Sunset className="h-4 w-4 text-orange-400" />
          <span>{formatSunTime(sunTimes.sunset)}</span>
        </div>
      )}
      {weather ? (
        <WeatherIndicator weather={weather} />
      ) : isProcessing ? (
        <Skeleton className="w-32 h-4" />
      ) : null}
    </div>
  );
}
