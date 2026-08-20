/// <reference types="jest" />
import { stageColor } from './stage-colors';

// Parse the hue (degrees) out of an "hsl(H, S%, L%)" string.
function hueOf(color: string): number {
  const m = /^hsl\(([\d.]+),/.exec(color);
  if (!m) throw new Error(`not an hsl() color: ${color}`);
  return Number(m[1]);
}

// Shortest angular distance between two hues on the 360deg wheel.
function hueDistance(a: number, b: number): number {
  const d = Math.abs(a - b) % 360;
  return Math.min(d, 360 - d);
}

describe('stageColor', () => {
  it('gives N stages N distinct colors', () => {
    const colors = Array.from({ length: 20 }, (_, i) => stageColor(i + 1));
    expect(new Set(colors).size).toBe(20);
  });

  it('keeps adjacent stages well separated in hue (golden-angle rotation)', () => {
    // Every consecutive pair lands ~137.5deg apart, so no two neighbours read as
    // similar colors even on a long trip.
    for (let day = 1; day < 40; day++) {
      const dist = hueDistance(hueOf(stageColor(day)), hueOf(stageColor(day + 1)));
      expect(dist).toBeGreaterThan(60);
    }
  });

  it('anchors the first stage near the brand orange hue', () => {
    expect(hueOf(stageColor(1))).toBe(25);
  });
});
