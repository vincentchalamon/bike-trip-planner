import { useTranslations } from "next-intl";
import {
  Sun,
  CloudSun,
  Cloud,
  CloudRain,
  CloudLightning,
  Snowflake,
  CloudFog,
  ArrowUp,
  ArrowDown,
  Bike,
  Mountain,
  Info,
} from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import type { WeatherData } from "@/lib/validation/schemas";

const weatherIconMap: Record<string, React.ElementType> = {
  "01d": Sun,
  "01n": Sun,
  "02d": CloudSun,
  "02n": CloudSun,
  "03d": Cloud,
  "03n": Cloud,
  "04d": Cloud,
  "04n": Cloud,
  "09d": CloudRain,
  "09n": CloudRain,
  "10d": CloudRain,
  "10n": CloudRain,
  "11d": CloudLightning,
  "11n": CloudLightning,
  "13d": Snowflake,
  "13n": Snowflake,
  "50d": CloudFog,
  "50n": CloudFog,
};

interface TripSummaryProps {
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  weather: WeatherData | null;
  isWeatherLoading?: boolean;
  isProcessing?: boolean;
}

export function TripSummary({
  totalDistance,
  totalElevation,
  totalElevationLoss,
  weather,
  isWeatherLoading,
  isProcessing,
}: TripSummaryProps) {
  const t = useTranslations("tripSummary");
  const showSkeleton =
    isProcessing && totalDistance === null && totalElevation === null;

  if (!showSkeleton && totalDistance === null && totalElevation === null)
    return null;

  const WeatherIcon = weather ? (weatherIconMap[weather.icon] ?? Cloud) : Cloud;

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
      </div>
      <p className="flex items-center justify-center gap-1 text-xs text-muted-foreground/70">
        <Info className="h-3 w-3" />
        {t("disclaimer")}
      </p>
    </div>
  );
}
