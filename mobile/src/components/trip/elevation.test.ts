/// <reference types="jest" />
import type { StageData } from '@btp/core';
import {
  buildProfilePoints,
  findClosestProfilePoint,
  haversineKm,
  profileHighlightSegment,
} from '@btp/core/elevation';

function stage(overrides: Partial<StageData> = {}): StageData {
  const zero = { lat: 0, lon: 0, ele: 0 };
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: zero,
    endPoint: zero,
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 10,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

describe('haversineKm', () => {
  it('is zero for identical points', () => {
    expect(haversineKm(48, 2, 48, 2)).toBe(0);
  });

  it('matches a known distance (~111 km per degree of latitude)', () => {
    expect(haversineKm(48, 2, 49, 2)).toBeCloseTo(111.19, 1);
  });
});

describe('buildProfilePoints', () => {
  it('returns no points for a stage with fewer than two coords', () => {
    expect(buildProfilePoints([stage({ geometry: [{ lat: 0, lon: 0, ele: 0 }] })], null)).toEqual(
      [],
    );
  });

  it('accumulates distance and carries stage/coord indices', () => {
    const s = stage({
      geometry: [
        { lat: 48, lon: 2, ele: 100 },
        { lat: 48.01, lon: 2, ele: 150 },
        { lat: 48.02, lon: 2, ele: 120 },
      ],
    });
    const points = buildProfilePoints([s], null);
    expect(points).toHaveLength(3);
    expect(points[0]).toMatchObject({ distanceKm: 0, ele: 100, stageIndex: 0, coordIndex: 0 });
    expect(points[1]!.distanceKm).toBeGreaterThan(0);
    expect(points[2]!.distanceKm).toBeGreaterThan(points[1]!.distanceKm);
    expect(points[2]).toMatchObject({ ele: 120, coordIndex: 2 });
  });

  it('excludes rest days and renumbers active-stage indices', () => {
    const a = stage({
      geometry: [
        { lat: 48, lon: 2, ele: 10 },
        { lat: 48.01, lon: 2, ele: 20 },
      ],
    });
    const rest = stage({ isRestDay: true, geometry: [] });
    const b = stage({
      geometry: [
        { lat: 49, lon: 3, ele: 30 },
        { lat: 49.01, lon: 3, ele: 40 },
      ],
    });
    const points = buildProfilePoints([a, rest, b], null);
    const stageIndices = [...new Set(points.map((p) => p.stageIndex))];
    expect(stageIndices).toEqual([0, 1]);
  });

  it('focuses a single active stage when given an index', () => {
    const a = stage({
      geometry: [
        { lat: 48, lon: 2, ele: 10 },
        { lat: 48.01, lon: 2, ele: 20 },
      ],
    });
    const b = stage({
      geometry: [
        { lat: 49, lon: 3, ele: 30 },
        { lat: 49.01, lon: 3, ele: 40 },
      ],
    });
    const points = buildProfilePoints([a, b], 1);
    expect(points.every((p) => p.stageIndex === 1)).toBe(true);
  });

  it('computes a positive gradient on a climb', () => {
    const s = stage({
      geometry: [
        { lat: 48, lon: 2, ele: 100 },
        { lat: 48.01, lon: 2, ele: 200 },
      ],
    });
    const points = buildProfilePoints([s], null);
    expect(points[1]!.gradient).toBeGreaterThan(0);
  });
});

describe('findClosestProfilePoint', () => {
  const points = [
    { distanceKm: 0 },
    { distanceKm: 5 },
    { distanceKm: 10 },
    { distanceKm: 20 },
  ];

  it('returns undefined for an empty array', () => {
    expect(findClosestProfilePoint([], 3)).toBeUndefined();
  });

  it('finds the nearest by distance', () => {
    expect(findClosestProfilePoint(points, 4)!.distanceKm).toBe(5);
    expect(findClosestProfilePoint(points, 12)!.distanceKm).toBe(10);
    expect(findClosestProfilePoint(points, 100)!.distanceKm).toBe(20);
  });
});

describe('profileHighlightSegment', () => {
  const s = stage({
    geometry: [
      { lat: 48, lon: 2, ele: 0 },
      { lat: 48.01, lon: 2.01, ele: 0 },
      { lat: 48.02, lon: 2.02, ele: 0 },
    ],
  });

  it('returns the hovered point and its next neighbour as [lon, lat]', () => {
    expect(profileHighlightSegment([s], 0, 0)).toEqual([
      [2, 48],
      [2.01, 48.01],
    ]);
  });

  it('falls back to the previous neighbour at the last coord', () => {
    expect(profileHighlightSegment([s], 0, 2)).toEqual([
      [2.02, 48.02],
      [2.01, 48.01],
    ]);
  });

  it('maps the active-stage index past rest days', () => {
    const rest = stage({ isRestDay: true });
    expect(profileHighlightSegment([rest, s], 0, 0)).toEqual([
      [2, 48],
      [2.01, 48.01],
    ]);
  });

  it('returns an empty segment when the point cannot be located', () => {
    expect(profileHighlightSegment([s], 5, 0)).toEqual([]);
    expect(profileHighlightSegment([s], 0, 9)).toEqual([]);
  });
});
