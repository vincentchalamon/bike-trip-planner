import { ArrowUp, ArrowDown, Bike, Mountain } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { WeatherIndicator } from "@/components/weather-indicator";
import type { WeatherData } from "@/lib/validation/schemas";

interface StageMetadataProps {
  distance: number | null;
  elevation: number | null;
  elevationLoss: number | null;
  weather: WeatherData | null;
  isProcessing?: boolean;
}

export function StageMetadata({
  distance,
  elevation,
  elevationLoss,
  weather,
  isProcessing,
}: StageMetadataProps) {
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
      {weather ? (
        <WeatherIndicator weather={weather} />
      ) : isProcessing ? (
        <Skeleton className="w-32 h-4" />
      ) : null}
    </div>
  );
}
