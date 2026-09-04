// Pure maths for the per-stage weather graph, shared by web (inline <svg>) and
// mobile (react-native-svg). No UI dependency: it turns the hourly riding-window
// series into an x/y plot model, SVG path strings and bar rects that both
// platforms render as-is. Mirrors the ElevationProfile convention (core/elevation.ts).

import type { HourlyWeatherData } from "./schemas";

export type RelativeWind = "headwind" | "tailwind" | "crosswind" | "unknown";

export interface WeatherSeries {
  points: HourlyWeatherData[];
  minHour: number;
  maxHour: number;
  /** Temperature domain (padded), spanning both real and apparent curves. */
  tempMin: number;
  tempMax: number;
  /** Peak precipitation (mm) over the window, for scaling rain bars (>= 1). */
  maxPrecipMm: number;
}

export interface PlotGeometry {
  width: number;
  height: number;
  padLeft: number;
  padRight: number;
  padTop: number;
  padBottom: number;
}

/**
 * Build the plot model, or null when there is nothing to plot. Points are sorted
 * by hour; the temperature domain is padded 1 °C and spans real + apparent so
 * neither curve clips.
 */
export function buildWeatherSeries(
  hourly: HourlyWeatherData[],
): WeatherSeries | null {
  if (hourly.length === 0) return null;

  const points = [...hourly].sort((a, b) => a.hour - b.hour);
  const temps = points.flatMap((p) => [p.temp, p.apparentTemp]);
  const rawMin = Math.min(...temps);
  const rawMax = Math.max(...temps);
  // Guard a flat series (min === max) so the y-projection never divides by zero.
  const tempMin = Math.floor(rawMin) - 1;
  const tempMax = Math.max(Math.ceil(rawMax) + 1, tempMin + 1);
  const maxPrecipMm = Math.max(1, ...points.map((p) => p.precipitationMm));

  return {
    points,
    minHour: points[0]!.hour,
    maxHour: points[points.length - 1]!.hour,
    tempMin,
    tempMax,
    maxPrecipMm,
  };
}

/** X pixel for an hour (clamped to the plot's horizontal band). */
export function hourToX(
  series: WeatherSeries,
  hour: number,
  geo: PlotGeometry,
): number {
  const span = series.maxHour - series.minHour || 1;
  const t = (hour - series.minHour) / span;
  return geo.padLeft + t * (geo.width - geo.padLeft - geo.padRight);
}

/** Y pixel for a temperature (higher temperature = higher on screen). */
export function tempToY(
  series: WeatherSeries,
  temp: number,
  geo: PlotGeometry,
): number {
  const span = series.tempMax - series.tempMin || 1;
  const t = (temp - series.tempMin) / span;
  return (
    geo.height - geo.padBottom - t * (geo.height - geo.padTop - geo.padBottom)
  );
}

/** SVG path `d` for the temperature (`"temp"`) or feels-like (`"apparentTemp"`) curve. */
export function tempPath(
  series: WeatherSeries,
  geo: PlotGeometry,
  key: "temp" | "apparentTemp",
): string {
  return series.points
    .map((p, i) => {
      const x = hourToX(series, p.hour, geo);
      const y = tempToY(series, p[key], geo);
      return `${i === 0 ? "M" : "L"}${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(" ");
}

export interface RainBar {
  hour: number;
  x: number;
  y: number;
  width: number;
  height: number;
  mm: number;
}

/**
 * Rain bars, one per hour, scaled to `maxPrecipMm`. Bars occupy up to the bottom
 * 40 % of the plot height so they read as a secondary layer under the curves.
 */
export function rainBars(series: WeatherSeries, geo: PlotGeometry): RainBar[] {
  const plotW = geo.width - geo.padLeft - geo.padRight;
  const span = series.points.length || 1;
  const barW = Math.max(2, (plotW / span) * 0.6);
  const maxBarH = (geo.height - geo.padTop - geo.padBottom) * 0.4;
  const baseY = geo.height - geo.padBottom;

  return series.points
    .filter((p) => p.precipitationMm > 0)
    .map((p) => {
      const h = (p.precipitationMm / series.maxPrecipMm) * maxBarH;
      const x = hourToX(series, p.hour, geo) - barW / 2;
      return {
        hour: p.hour,
        x,
        y: baseY - h,
        width: barW,
        height: h,
        mm: p.precipitationMm,
      };
    });
}

/**
 * Rotation (degrees) for an up-pointing arrow glyph so it flows in the direction
 * the wind blows *towards* (meteorological `windDirectionDeg` is where it comes
 * FROM, so add 180).
 */
export function windArrowRotation(windDirectionDeg: number): number {
  return (windDirectionDeg + 180) % 360;
}

/**
 * The hour point nearest a horizontal fraction (0 = left edge, 1 = right edge),
 * for a crosshair readout. Returns null for an empty series.
 */
export function pointAtFraction(
  series: WeatherSeries,
  fraction: number,
): HourlyWeatherData | null {
  if (series.points.length === 0) return null;
  const span = series.maxHour - series.minHour || 1;
  const targetHour = series.minHour + Math.min(1, Math.max(0, fraction)) * span;
  let closest = series.points[0]!;
  for (const p of series.points) {
    if (Math.abs(p.hour - targetHour) < Math.abs(closest.hour - targetHour)) {
      closest = p;
    }
  }
  return closest;
}
