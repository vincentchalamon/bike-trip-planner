/**
 * Naismith-adapted travel time estimation for cycling.
 *
 * Formula (adapted from Naismith's rule):
 *   effectiveSpeed = max(5, baseSpeed - 2 * (elevationGainM / 500))
 *   ridingDuration = distanceKm / effectiveSpeed   (hours)
 *
 * Breaks are added on top of riding time:
 *   - Short break: 10 min per 2 full riding hours
 *   - Lunch break: 1 h if noon falls within the riding window
 *
 *   arrivalDecimalHour = departureHour + ridingDuration + breakDuration
 *
 * The elevation penalty (-2 km/h per 500m D+) mirrors the backend RiderTimeEstimator.
 */

const MIN_EFFECTIVE_SPEED_KMH = 5;
const ELEVATION_PENALTY_PER_500M = 2;

/**
 * Computes effective cycling speed after applying the elevation penalty.
 */
export function computeEffectiveSpeed(
  baseSpeedKmh: number,
  elevationGainM: number,
): number {
  const penalty = ELEVATION_PENALTY_PER_500M * (elevationGainM / 500);
  return Math.max(MIN_EFFECTIVE_SPEED_KMH, baseSpeedKmh - penalty);
}

/**
 * Computes total break duration in decimal hours for a stage.
 *
 * - Short break: 10 min per 2 full riding hours
 * - Lunch break: 1 h if noon falls within the riding window
 */
export function computeBreakDuration(
  ridingDurationH: number,
  departureHour: number,
): number {
  const shortBreaks = Math.floor(ridingDurationH / 2) * (10 / 60);
  const noonBreak =
    departureHour < 12 && departureHour + ridingDurationH > 12 ? 1.0 : 0.0;
  return shortBreaks + noonBreak;
}

/**
 * Estimates total riding duration in decimal hours for a stage.
 */
export function estimateRidingDuration(
  distanceKm: number,
  averageSpeedKmh: number,
  elevationGainM: number,
): number {
  if (distanceKm <= 0) return 0;
  const effectiveSpeed = computeEffectiveSpeed(averageSpeedKmh, elevationGainM);
  return distanceKm / effectiveSpeed;
}

/**
 * Formats a decimal hour (e.g. 13.5) as "13h30".
 * Hours wrap around 24 (e.g. 25.0 → "1h00").
 */
export function formatDecimalHour(decimalHour: number): string {
  const normalised = ((decimalHour % 24) + 24) % 24;
  const h = Math.floor(normalised);
  const m = Math.round((normalised - h) * 60);
  // Handle rounding to 60 minutes
  if (m === 60) {
    const h2 = (h + 1) % 24;
    return `${h2}h00`;
  }
  return `${h}h${String(m).padStart(2, "0")}`;
}

/**
 * Computes departure and arrival decimal hours for a stage.
 *
 * @returns `{ departureDecimal, arrivalDecimal }` — decimal hours
 */
export function computeStageTimes(
  departureHour: number,
  distanceKm: number,
  averageSpeedKmh: number,
  elevationGainM: number,
): { departureDecimal: number; arrivalDecimal: number } {
  const ridingDuration = estimateRidingDuration(
    distanceKm,
    averageSpeedKmh,
    elevationGainM,
  );
  const breakDuration = computeBreakDuration(ridingDuration, departureHour);
  return {
    departureDecimal: departureHour,
    arrivalDecimal: departureHour + ridingDuration + breakDuration,
  };
}
