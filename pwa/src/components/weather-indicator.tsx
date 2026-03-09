import { weatherIconMap, DefaultWeatherIcon } from "@/lib/weather-icons";
import type { WeatherData } from "@/lib/validation/schemas";

interface WeatherIndicatorProps {
  weather: WeatherData | null;
}

export function WeatherIndicator({ weather }: WeatherIndicatorProps) {
  if (!weather) return null;

  const Icon = weatherIconMap[weather.icon] ?? DefaultWeatherIcon;

  return (
    <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
      <Icon className="h-4 w-4" />
      <span>
        {weather.description}, {Math.round(weather.tempMin)}-
        {Math.round(weather.tempMax)}°C
      </span>
    </div>
  );
}
