/// <reference types="jest" />
import type { HourlyWeatherData } from '@btp/core';
import {
  buildWeatherSeries,
  hourToX,
  tempToY,
  tempPath,
  rainBars,
  pointAtFraction,
  windArrowRotation,
  type PlotGeometry,
} from '@btp/core/weather-series';

const GEO: PlotGeometry = {
  width: 100,
  height: 100,
  padLeft: 0,
  padRight: 0,
  padTop: 0,
  padBottom: 0,
};

function slot(
  hour: number,
  overrides: Partial<HourlyWeatherData> = {},
): HourlyWeatherData {
  return {
    hour,
    temp: 15,
    apparentTemp: 13,
    precipitationMm: 0,
    precipitationProbability: 0,
    windSpeed: 10,
    windGusts: 15,
    windDirectionDeg: 0,
    relativeWindDirection: 'unknown',
    weatherCode: 3,
    ...overrides,
  };
}

describe('weather-series', () => {
  it('returns null for an empty series', () => {
    expect(buildWeatherSeries([])).toBeNull();
  });

  it('spans the hour range and a padded temperature domain', () => {
    const series = buildWeatherSeries([
      slot(8, { temp: 12, apparentTemp: 10 }),
      slot(10, { temp: 20, apparentTemp: 22 }),
    ]);
    expect(series).not.toBeNull();
    expect(series!.minHour).toBe(8);
    expect(series!.maxHour).toBe(10);
    // domain padded 1° around [10, 22]
    expect(series!.tempMin).toBe(9);
    expect(series!.tempMax).toBe(23);
  });

  it('projects hours and temperatures across the plot', () => {
    const series = buildWeatherSeries([
      slot(8, { temp: 9 }),
      slot(12, { temp: 21 }),
    ])!;
    expect(hourToX(series, 8, GEO)).toBeCloseTo(0);
    expect(hourToX(series, 12, GEO)).toBeCloseTo(100);
    // higher temperature sits higher on screen (smaller y)
    expect(tempToY(series, series.tempMax, GEO)).toBeLessThan(
      tempToY(series, series.tempMin, GEO),
    );
  });

  it('builds an SVG path starting with a move command', () => {
    const series = buildWeatherSeries([slot(8), slot(9)])!;
    expect(tempPath(series, GEO, 'temp')).toMatch(/^M/);
  });

  it('emits a rain bar only for hours with precipitation', () => {
    const series = buildWeatherSeries([
      slot(8, { precipitationMm: 0 }),
      slot(9, { precipitationMm: 2 }),
    ])!;
    const bars = rainBars(series, GEO);
    expect(bars).toHaveLength(1);
    expect(bars[0]!.hour).toBe(9);
  });

  it('finds the nearest hour to a horizontal fraction', () => {
    const series = buildWeatherSeries([slot(8), slot(10), slot(12)])!;
    expect(pointAtFraction(series, 0)!.hour).toBe(8);
    expect(pointAtFraction(series, 1)!.hour).toBe(12);
    expect(pointAtFraction(series, 0.5)!.hour).toBe(10);
  });

  it('rotates the wind arrow to the blowing-to direction', () => {
    expect(windArrowRotation(0)).toBe(180);
    expect(windArrowRotation(270)).toBe(90);
  });
});
