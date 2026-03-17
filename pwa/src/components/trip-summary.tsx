import { useTranslations } from "next-intl";
import { ArrowUp, ArrowDown, Bike, Mountain, Info, Wallet } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { weatherIconMap, DefaultWeatherIcon } from "@/lib/weather-icons";
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
}: TripSummaryProps) {
  const t = useTranslations("tripSummary");
  const showSkeleton =
    isProcessing && totalDistance === null && totalElevation === null;

  if (!showSkeleton && totalDistance === null && totalElevation === null)
    return null;

  const WeatherIcon = weather
    ? (weatherIconMap[weather.icon] ?? DefaultWeatherIcon)
    : DefaultWeatherIcon;

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-center gap-6 text-sm text-muted-foreground">
        <div className="flex items-center gap-1.5">
          <Bike className="h-4 w-4 text-brand" />
          {totalDistance !== null ? (
            <span>
              {t("totalDistance")}{" "}
              <span data-testid="total-distance">
                {Math.round(totalDistance)}km
              </span>
            </span>
          ) : (
            <Skeleton className="w-24 h-4" />
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
              <Wallet className="h-4 w-4 text-green-600" />
              <span data-testid="estimated-budget">
                {t("estimatedBudget")}{" "}
                {Math.round(estimatedBudgetMin)}€ — {Math.round(estimatedBudgetMax)}€
              </span>
            </div>
          )}
      </div>
      <p className="flex items-center justify-center gap-1 text-xs text-muted-foreground/70">
        <Info className="h-3 w-3" />
        {t("disclaimer")}
      </p>
    </div>
  );
}
