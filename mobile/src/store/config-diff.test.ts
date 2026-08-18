/// <reference types="jest" />
import type { StageData } from '@btp/core';
import { diffStageIndices } from './config-diff';

const P = { lat: 0, lon: 0, ele: 0 };

function stage(overrides: Partial<StageData> = {}): StageData {
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: P,
    endPoint: { lat: 1, lon: 1, ele: 0 },
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

describe('diffStageIndices', () => {
  it('is empty when nothing changed', () => {
    const before = [stage(), stage({ dayNumber: 2 })];
    const after = [stage(), stage({ dayNumber: 2 })];
    expect(diffStageIndices(before, after).size).toBe(0);
  });

  it('flags only the stage whose distance moved', () => {
    const before = [stage(), stage({ dayNumber: 2, distance: 40 })];
    const after = [stage(), stage({ dayNumber: 2, distance: 55 })];
    const diff = diffStageIndices(before, after);
    expect(diff.has(0)).toBe(false);
    expect(diff.has(1)).toBe(true);
  });

  it('ignores sub-unit jitter (values render rounded)', () => {
    const before = [stage({ distance: 50.1, elevation: 100.2 })];
    const after = [stage({ distance: 50.4, elevation: 100.1 })];
    expect(diffStageIndices(before, after).size).toBe(0);
  });

  it('flags a moved endpoint', () => {
    const before = [stage()];
    const after = [stage({ endPoint: { lat: 2, lon: 2, ele: 0 } })];
    expect(diffStageIndices(after, before).has(0)).toBe(true);
  });

  it('flags every index when the count changed (re-split)', () => {
    const before = [stage()];
    const after = [stage(), stage({ dayNumber: 2 }), stage({ dayNumber: 3 })];
    const diff = diffStageIndices(before, after);
    expect([...diff].sort()).toEqual([0, 1, 2]);
  });
});
