import {
  Sun,
  CloudSun,
  Cloud,
  CloudRain,
  CloudLightning,
  Snowflake,
  CloudFog,
} from "lucide-react";
import type { WeatherData } from "@/lib/validation/schemas";

const iconMap: Record<string, React.ElementType> = {
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

interface WeatherIndicatorProps {
  weather: WeatherData | null;
}

export function WeatherIndicator({ weather }: WeatherIndicatorProps) {
  if (!weather) return null;

  const Icon = iconMap[weather.icon] ?? Cloud;

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
