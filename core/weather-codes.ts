// WMO weather-code -> stable condition key, shared by web and mobile so per-hour
// labels (strip aria-labels, hourly table) don't duplicate the backend's
// WmoWeatherMapper. Consumers translate `weather.condition.<key>`.

export type WeatherConditionKey =
  | "clear_sky"
  | "mainly_clear"
  | "partly_cloudy"
  | "overcast"
  | "fog"
  | "drizzle"
  | "rain"
  | "snow"
  | "rain_showers"
  | "snow_showers"
  | "thunderstorm"
  | "unknown";

/** Maps a WMO code to its condition key (same buckets as the backend mapper). */
export function weatherCodeToConditionKey(code: number): WeatherConditionKey {
  if (code === 0) return "clear_sky";
  if (code === 1) return "mainly_clear";
  if (code === 2) return "partly_cloudy";
  if (code === 3) return "overcast";
  if (code === 45 || code === 48) return "fog";
  if (code >= 51 && code <= 57) return "drizzle";
  if (code >= 61 && code <= 67) return "rain";
  if (code >= 71 && code <= 77) return "snow";
  if (code >= 80 && code <= 82) return "rain_showers";
  if (code === 85 || code === 86) return "snow_showers";
  if (code >= 95 && code <= 99) return "thunderstorm";
  return "unknown";
}
