/// <reference types="jest" />
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import { computeProfileSummary, groupThousands } from './trip-map-summary';

function stage(overrides: Partial<StageData> = {}): StageData {
  const zero = { lat: 0, lon: 0, ele: 0 };
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 120,
    elevationLoss: 0,
    startPoint: zero,
    endPoint: zero,
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    resupply: EMPTY_RESUPPLY,
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 10,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

const routed = stage({
  geometry: [
    { lat: 48, lon: 2, ele: 100 },
    { lat: 48.1, lon: 2.1, ele: 200 },
    { lat: 48.2, lon: 2.2, ele: 150 },
  ],
});

describe('groupThousands', () => {
  it('space-groups thousands and rounds to an integer', () => {
    expect(groupThousands(0)).toBe('0');
    expect(groupThousands(240)).toBe('240');
    expect(groupThousands(5240)).toBe('5 240');
    expect(groupThousands(1234567)).toBe('1 234 567');
    expect(groupThousands(1499.6)).toBe('1 500');
  });
});

describe('computeProfileSummary', () => {
  it('returns null until the route has at least two profile points', () => {
    expect(computeProfileSummary([])).toBeNull();
    // A single-coord stage yields no profile points (needs >= 2).
    expect(computeProfileSummary([stage({ geometry: [{ lat: 48, lon: 2, ele: 100 }] })])).toBeNull();
  });

  it('derives distance, gain and endpoint/max elevations from the profile', () => {
    const summary = computeProfileSummary([routed]);
    expect(summary).not.toBeNull();
    expect(summary!.startEle).toBe(100);
    expect(summary!.endEle).toBe(150);
    expect(summary!.maxEle).toBe(200);
    expect(summary!.gain).toBe(120); // the stage's D+
    expect(summary!.distanceKm).toBeGreaterThan(0);
  });

  it('excludes rest days from the summed gain', () => {
    const summary = computeProfileSummary([
      routed,
      stage({ isRestDay: true, elevation: 999, geometry: [] }),
    ]);
    expect(summary!.gain).toBe(120); // rest day's 999 not counted
  });
});
