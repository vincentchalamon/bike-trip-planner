import {
  Sun,
  CloudSun,
  Cloud,
  CloudRain,
  CloudLightning,
  Snowflake,
  CloudFog,
} from "lucide-react";

/** Map OpenWeather icon codes to lucide-react components */
export const weatherIconMap: Record<string, React.ElementType> = {
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

/** Default weather icon when code is not found */
export const DefaultWeatherIcon = Cloud;

/** Lucide icon for a WMO weather code (per-hour strip / graph, mirrors backend buckets). */
export function weatherIconForCode(code: number): React.ElementType {
  if (code === 0 || code === 1) return Sun;
  if (code === 2) return CloudSun;
  if (code === 3) return Cloud;
  if (code === 45 || code === 48) return CloudFog;
  if (code >= 51 && code <= 67) return CloudRain;
  if (code >= 71 && code <= 77) return Snowflake;
  if (code >= 80 && code <= 82) return CloudRain;
  if (code === 85 || code === 86) return Snowflake;
  if (code >= 95 && code <= 99) return CloudLightning;
  return Cloud;
}
