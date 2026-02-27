import { Bike, Mountain } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { WeatherIndicator } from "@/components/weather-indicator";
import type { WeatherData } from "@/lib/validation/schemas";

interface StageMetadataProps {
  distance: number | null;
  elevation: number | null;
  weather: WeatherData | null;
}

export function StageMetadata({
  distance,
  elevation,
  weather,
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
          <span>{Math.round(elevation)}m</span>
        ) : (
          <Skeleton className="w-12 h-4" />
        )}
      </div>
      {weather && <WeatherIndicator weather={weather} />}
    </div>
  );
}
