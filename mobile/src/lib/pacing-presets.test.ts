/// <reference types="jest" />
// Exercises the shared pacing-presets module extracted to @btp/core (ADR-055,
// #1046) from the mobile consumer, mirroring how mobile jest covers the other
// framework-free core modules (reconciliation, elevation).
import {
  PRESETS,
  fromElevationPercent,
  fromFatiguePercent,
  getActivePresetKey,
  toElevationPercent,
  toFatiguePercent,
} from '@btp/core/pacing-presets';

describe('pacing-presets conversions', () => {
  it('round-trips fatigue percent <-> factor', () => {
    expect(fromFatiguePercent(20)).toBeCloseTo(0.8);
    expect(toFatiguePercent(0.8)).toBe(20);
    expect(toFatiguePercent(fromFatiguePercent(35))).toBe(35);
  });

  it('round-trips elevation percent <-> penalty', () => {
    expect(fromElevationPercent(20)).toBe(100);
    expect(toElevationPercent(100)).toBe(20);
    expect(toElevationPercent(fromElevationPercent(30))).toBe(30);
  });
});

describe('getActivePresetKey', () => {
  it('matches an exact preset', () => {
    const inter = PRESETS.find((p) => p.key === 'intermediate')!;
    expect(
      getActivePresetKey(
        inter.maxDistancePerDay,
        inter.averageSpeed,
        fromElevationPercent(inter.elevationPenaltyPercent),
        fromFatiguePercent(inter.fatiguePercent),
      ),
    ).toBe('intermediate');
  });

  it('returns null for a custom (non-preset) combination', () => {
    expect(getActivePresetKey(77, 13, 42, 0.77)).toBeNull();
  });
});
