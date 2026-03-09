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
