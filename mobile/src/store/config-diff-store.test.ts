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

  it('does not lose the second highlight when two recomputes are armed before their trip_ready', () => {
    jest.useFakeTimers();
    try {
      useTripStore.setState({ stages: [stage(), stage({ dayNumber: 2 })] });
      // Two destructive recomputes armed back-to-back, both before any
      // trip_ready streams back (they share the single diffBaseline slot).
      useTripStore.getState().armConfigDiff();
      useTripStore.getState().armConfigDiff();

      // First trip_ready must NOT release the baseline (a second generation is
      // still pending), otherwise the second trip_ready would find it null.
      useTripStore
        .getState()
        .applyTripReady([stage({ distance: 88 }), stage({ dayNumber: 2 })]);
      expect(useTripStore.getState().diffBaseline).not.toBeNull();

      // Second trip_ready diffs against the still-armed baseline: its highlight
      // (stage 1 moved too) is preserved, not silently dropped.
      useTripStore
        .getState()
        .applyTripReady([stage({ distance: 88 }), stage({ dayNumber: 2, distance: 88 })]);
      const { stageDiffs, diffBaseline } = useTripStore.getState();
      expect(stageDiffs.has(1)).toBe(true);
      // The baseline is released once the last armed generation is consumed.
      expect(diffBaseline).toBeNull();
    } finally {
      jest.useRealTimers();
    }
  });

  it('keeps the second highlight when a first armed recompute fails while a second is pending', () => {
    jest.useFakeTimers();
    try {
      useTripStore.setState({ stages: [stage(), stage({ dayNumber: 2 })] });
      // Two destructive recomputes armed back-to-back (shared baseline slot).
      useTripStore.getState().armConfigDiff();
      useTripStore.getState().armConfigDiff();

      // The FIRST commit fails (422/network): disarm must not null the baseline
      // out from under the still-pending second generation.
      useTripStore.getState().disarmConfigDiff();
      expect(useTripStore.getState().diffBaseline).not.toBeNull();

      // The SECOND recompute streams back and still diffs against the baseline.
      useTripStore
        .getState()
        .applyTripReady([stage({ distance: 88 }), stage({ dayNumber: 2, distance: 88 })]);
      const { stageDiffs, diffBaseline } = useTripStore.getState();
      expect(stageDiffs.has(0)).toBe(true);
      expect(stageDiffs.has(1)).toBe(true);
      expect(diffBaseline).toBeNull();
    } finally {
      jest.useRealTimers();
    }
  });

  it("a stale expiry timer does not wipe a fresher recompute's highlights", () => {
    jest.useFakeTimers();
    try {
      useTripStore.setState({ stages: [stage(), stage({ dayNumber: 2 })] });
      // First destructive recompute: highlights stage 0.
      useTripStore.getState().armConfigDiff();
      useTripStore
        .getState()
        .applyTripReady([stage({ distance: 88 }), stage({ dayNumber: 2 })]);
      expect([...useTripStore.getState().stageDiffs]).toEqual([0]);

      // A second destructive recompute lands within the TTL: highlights stage 1.
      jest.advanceTimersByTime(DIFF_TTL_MS - 1);
      useTripStore.getState().armConfigDiff();
      useTripStore
        .getState()
        .applyTripReady([stage({ distance: 88 }), stage({ dayNumber: 2, distance: 42 })]);
      expect([...useTripStore.getState().stageDiffs]).toEqual([1]);

      // The FIRST timer now fires — it must be a no-op (stageDiffs was replaced).
      jest.advanceTimersByTime(1);
      expect([...useTripStore.getState().stageDiffs]).toEqual([1]);

      // The SECOND timer still expires the fresh highlights on schedule.
      jest.advanceTimersByTime(DIFF_TTL_MS);
      expect(useTripStore.getState().stageDiffs.size).toBe(0);
    } finally {
      jest.useRealTimers();
    }
  });
});
