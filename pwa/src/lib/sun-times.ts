/**
 * Sunrise/sunset calculation for a given date and coordinates.
 *
 * Uses the NOAA solar position algorithm (Spencer 1971 / Iqbal 1983),
 * the same model underlying PHP's native date_sun_info().
 *
 * Civil twilight: sun is 6° below horizon — sufficient light to ride safely.
 * Nautical twilight: sun is 12° below horizon.
 *
 * All times are returned as decimal hours in UTC.
 * Returns null for polar day (sun never sets) or polar night (sun never rises).
 */

const DEG_TO_RAD = Math.PI / 180;
const RAD_TO_DEG = 180 / Math.PI;

/**
 * Computes solar declination and equation of time for a given day of year.
 */
function solarPosition(dayOfYear: number): { declination: number; eqTime: number } {
  // Fractional year in radians
  const gamma = (2 * Math.PI * (dayOfYear - 1)) / 365;

  // Equation of time (minutes)
  const eqTime =
    229.18 *
    (0.000075 +
      0.001868 * Math.cos(gamma) -
      0.032077 * Math.sin(gamma) -
      0.014615 * Math.cos(2 * gamma) -
      0.04089 * Math.sin(2 * gamma));

  // Solar declination (radians)
  const declination =
    0.006918 -
    0.399912 * Math.cos(gamma) +
    0.070257 * Math.sin(gamma) -
    0.006758 * Math.cos(2 * gamma) +
    0.000907 * Math.sin(2 * gamma) -
    0.002697 * Math.cos(3 * gamma) +
    0.00148 * Math.sin(3 * gamma);

  return { declination, eqTime };
}

/**
 * Computes the hour angle (in minutes from solar noon) at which the sun
 * reaches a given elevation angle `zenith` (in degrees from zenith = 90 + depression).
 *
 * Returns null for polar day or polar night.
 */
function hourAngle(latRad: number, declination: number, zenithDeg: number): number | null {
  const cosZenith = Math.cos(zenithDeg * DEG_TO_RAD);
  const cosLat = Math.cos(latRad);
  const sinLat = Math.sin(latRad);
  const cosDec = Math.cos(declination);
  const sinDec = Math.sin(declination);

  const cosHa = (cosZenith - sinLat * sinDec) / (cosLat * cosDec);

  if (cosHa < -1) return null; // Polar day (sun never sets below this zenith)
  if (cosHa > 1) return null; // Polar night (sun never rises above this zenith)

  return Math.acos(cosHa) * RAD_TO_DEG;
}

/**
 * Computes sunrise, sunset, and civil twilight times for a given date and location.
 *
 * @param date    The date to compute for (only the calendar date is used, not the time)
 * @param lat     Latitude in decimal degrees (positive = north)
 * @param lon     Longitude in decimal degrees (positive = east)
 * @returns Object with decimal UTC hours (e.g. 6.5 = 06:30 UTC), or null for polar day/night
 */
export function computeSunTimes(
  date: Date,
  lat: number,
  lon: number,
): {
  sunrise: number | null;
  sunset: number | null;
  civilTwilightBegin: number | null;
  civilTwilightEnd: number | null;
} {
  // Day of year (1-365)
  const start = Date.UTC(date.getUTCFullYear(), 0, 0);
  const diff = Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()) - start;
  const dayOfYear = Math.floor(diff / 86_400_000);

  const { declination, eqTime } = solarPosition(dayOfYear);
  const latRad = lat * DEG_TO_RAD;

  // Solar noon in minutes from midnight UTC
  const solarNoon = 720 - 4 * lon - eqTime;

  // Sunrise/sunset: zenith = 90.833° (refraction + solar disc)
  const haSunrise = hourAngle(latRad, declination, 90.833);
  const sunrise = haSunrise !== null ? (solarNoon - 4 * haSunrise) / 60 : null;
  const sunset = haSunrise !== null ? (solarNoon + 4 * haSunrise) / 60 : null;

  // Civil twilight: zenith = 96°
  const haCivil = hourAngle(latRad, declination, 96);
  const civilTwilightBegin = haCivil !== null ? (solarNoon - 4 * haCivil) / 60 : null;
  const civilTwilightEnd = haCivil !== null ? (solarNoon + 4 * haCivil) / 60 : null;

  return { sunrise, sunset, civilTwilightBegin, civilTwilightEnd };
}

/**
 * Formats a decimal UTC hour as "HH:MM".
 * Returns null if the input is null (polar day/night).
 */
export function formatSunTime(decimalUtcHour: number | null): string | null {
  if (decimalUtcHour === null) return null;
  const normalised = ((decimalUtcHour % 24) + 24) % 24;
  const h = Math.floor(normalised);
  const m = Math.round((normalised - h) * 60);
  if (m === 60) {
    return `${String((h + 1) % 24).padStart(2, "0")}:00`;
  }
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

/**
 * Computes the stage date given a trip start date (ISO string) and a 0-based stage index.
 * Returns null if the start date is not provided.
 */
export function computeStageDate(
  startDate: string | null,
  stageIndex: number,
): Date | null {
  if (!startDate) return null;
  const base = new Date(startDate + "T00:00:00Z");
  if (isNaN(base.getTime())) return null;
  base.setUTCDate(base.getUTCDate() + stageIndex);
  return base;
}
