/// <reference types="jest" />
import type { StageData } from '@btp/core';
import { useTripStore } from './trip-store';
import { DIFF_TTL_MS } from './config-diff';

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

beforeEach(() => {
  useTripStore.getState().reset();
});

describe('destructive config diff arming', () => {
  it('does not highlight when no baseline is armed', () => {
    useTripStore.setState({ stages: [stage()] });
    useTripStore.getState().applyTripReady([stage({ distance: 90 })]);
    expect(useTripStore.getState().stageDiffs.size).toBe(0);
  });

  it('highlights the changed stage after an armed destructive recompute', () => {
    jest.useFakeTimers(); // swallow the auto-expiry timer so it can't leak
    try {
      useTripStore.setState({ stages: [stage(), stage({ dayNumber: 2 })] });
      useTripStore.getState().armConfigDiff();
      useTripStore
        .getState()
        .applyTripReady([stage(), stage({ dayNumber: 2, distance: 88 })]);
      const { stageDiffs, diffBaseline } = useTripStore.getState();
      expect(stageDiffs.has(0)).toBe(false);
      expect(stageDiffs.has(1)).toBe(true);
      // Baseline is consumed so a follow-up ordinary recompute won't re-diff.
      expect(diffBaseline).toBeNull();
    } finally {
      jest.useRealTimers();
    }
  });

  it('auto-clears the highlight after the TTL', () => {
    jest.useFakeTimers();
    try {
      useTripStore.setState({ stages: [stage()] });
      useTripStore.getState().armConfigDiff();
      useTripStore.getState().applyTripReady([stage({ distance: 88 })]);
      expect(useTripStore.getState().stageDiffs.size).toBe(1);
      jest.advanceTimersByTime(DIFF_TTL_MS);
      expect(useTripStore.getState().stageDiffs.size).toBe(0);
    } finally {
      jest.useRealTimers();
    }
  });
});
