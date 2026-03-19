import { useTranslations, useLocale } from "next-intl";
import dayjs from "dayjs";
import "dayjs/locale/fr";
import "dayjs/locale/en";
import {
  ArrowUp,
  ArrowDown,
  Bike,
  Mountain,
  Info,
  Euro,
  CalendarDays,
  User,
} from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { weatherIconMap, DefaultWeatherIcon } from "@/lib/weather-icons";
import { getActivePresetKey } from "@/lib/pacing-presets";
import { useUiStore } from "@/store/ui-store";
import type { WeatherData } from "@/lib/validation/schemas";

interface TripSummaryProps {
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  weather: WeatherData | null;
  isWeatherLoading?: boolean;
  isProcessing?: boolean;
  estimatedBudgetMin?: number;
  estimatedBudgetMax?: number;
  startDate: string | null;
  endDate: string | null;
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
}

export function TripSummary({
  totalDistance,
  totalElevation,
  totalElevationLoss,
  weather,
  isWeatherLoading,
  isProcessing,
  estimatedBudgetMin,
  estimatedBudgetMax,
  startDate,
  endDate,
  fatigueFactor,
  elevationPenalty,
  maxDistancePerDay,
  averageSpeed,
}: TripSummaryProps) {
  const t = useTranslations("tripSummary");
  const tPacing = useTranslations("pacing");
  const locale = useLocale();
  const openConfigPanelAt = useUiStore((s) => s.openConfigPanelAt);
  const showSkeleton =
    isProcessing && totalDistance === null && totalElevation === null;

  if (!showSkeleton && totalDistance === null && totalElevation === null)
    return null;

  const WeatherIcon = weather
    ? (weatherIconMap[weather.icon] ?? DefaultWeatherIcon)
    : DefaultWeatherIcon;

  const activePresetKey = getActivePresetKey(
    maxDistancePerDay,
    averageSpeed,
    elevationPenalty,
    fatigueFactor,
  );

  const profileLabel = activePresetKey
    ? tPacing(`preset_${activePresetKey}`)
    : t("customProfile");

  const formatDateRange = (start: string, end: string) => {
    const s = dayjs(start).locale(locale);
    const e = dayjs(end).locale(locale);
    if (s.month() === e.month() && s.year() === e.year()) {
      // Same month: "19 → 21 mars"
      return `${s.date()} → ${e.format("D MMM")}`;
    }
    // Different months: "31 mars → 2 avril"
    return `${s.format("D MMM")} → ${e.format("D MMM")}`;
  };

  const datesDisplay =
    startDate && endDate
      ? formatDateRange(startDate, endDate)
      : startDate
        ? dayjs(startDate).locale(locale).format("D MMM")
        : t("noDates");

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-center gap-x-6 gap-y-1 text-sm text-muted-foreground">
        <div className="flex items-center gap-1.5">
          <Bike className="h-4 w-4 text-brand" />
          {totalDistance !== null ? (
            <span data-testid="total-distance">
              {Math.round(totalDistance)}km
            </span>
          ) : (
            <Skeleton className="w-16 h-4" />
          )}
        </div>
        <div className="flex items-center gap-1.5">
          <Mountain className="h-4 w-4 text-orange-500" />
          {totalElevation !== null ? (
            <span
              className="flex items-center gap-1"
              data-testid="total-elevation"
            >
              <ArrowUp className="h-3 w-3 text-red-500" />
              {Math.round(totalElevation)}m
              <ArrowDown className="h-3 w-3 text-blue-500" />
              {Math.round(totalElevationLoss ?? 0)}m
            </span>
          ) : (
            <Skeleton className="w-24 h-4" />
          )}
        </div>
        {/* Force line break on mobile after distance+elevation */}
        <div className="basis-full h-0 md:hidden" aria-hidden="true" />
        <div className="flex items-center gap-1.5">
          {weather ? (
            <>
              <WeatherIcon className="h-4 w-4" />
              <span>
                {weather.description}, {Math.round(weather.tempMin)}-
                {Math.round(weather.tempMax)}°C
              </span>
            </>
          ) : isWeatherLoading ? (
            <Skeleton className="w-32 h-4" />
          ) : null}
        </div>
        {estimatedBudgetMin !== undefined &&
          estimatedBudgetMax !== undefined &&
          (estimatedBudgetMin > 0 || estimatedBudgetMax > 0) && (
            <div className="flex items-center gap-1.5">
              <Euro className="h-4 w-4 text-green-600" />
              <span data-testid="estimated-budget">
                {Math.round(estimatedBudgetMin)}€ —{" "}
                {Math.round(estimatedBudgetMax)}€
              </span>
            </div>
          )}
        {/* Force line break on mobile */}
        <div className="basis-full h-0 md:hidden" aria-hidden="true" />
        {/* Dates chip — clickable → opens ConfigPanel dates section */}
        <button
          type="button"
          className="flex items-center gap-1.5 hover:text-foreground transition-colors cursor-pointer"
          onClick={() => openConfigPanelAt("dates")}
          aria-label={t("datesLabel")}
          data-testid="summary-dates"
        >
          <CalendarDays className="h-4 w-4 text-brand" />
          <span>{datesDisplay}</span>
        </button>
        {/* Cyclo profile chip — clickable → opens ConfigPanel pacing section */}
        <button
          type="button"
          className="flex items-center gap-1.5 hover:text-foreground transition-colors cursor-pointer"
          onClick={() => openConfigPanelAt("pacing")}
          aria-label={t("profileLabel")}
          data-testid="summary-profile"
        >
          <User className="h-4 w-4 text-brand" />
          <span>{profileLabel}</span>
        </button>
      </div>
      <p className="flex items-center justify-center gap-1 text-xs text-muted-foreground/70">
        <Info className="h-3 w-3" />
        {t("disclaimer")}
      </p>
    </div>
  );
}
