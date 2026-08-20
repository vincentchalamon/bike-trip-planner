/// <reference types="jest" />
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import {
  alertSegmentToCoords,
  applyZoom,
  buildSatelliteStyle,
  buildStageLines,
  collectMarkers,
  computeBounds,
  mapStyleFor,
  markerCollection,
  POSITRON_STYLE_URL,
  SATELLITE_TILE_URL,
  segmentFeature,
} from './map-utils';

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

describe('computeBounds', () => {
  it('returns null for no coordinates', () => {
    expect(computeBounds([])).toBeNull();
  });

  it('frames every coordinate as [west, south, east, north]', () => {
    const bounds = computeBounds([
      [2, 48],
      [5, 45],
      [-1, 50],
    ]);
    expect(bounds).toEqual([-1, 45, 5, 50]);
  });
});

describe('buildStageLines', () => {
  const geom = [
    { lat: 48, lon: 2, ele: 100 },
    { lat: 48.1, lon: 2.1, ele: 150 },
  ];

  it('emits one colored line per drawable stage, in [lon, lat] order', () => {
    const lines = buildStageLines([
      stage({ dayNumber: 1, geometry: geom }),
      stage({ dayNumber: 2, geometry: geom }),
    ]);
    expect(lines).toHaveLength(2);
    expect(lines[0]!.coordinates).toEqual([
      [2, 48],
      [2.1, 48.1],
    ]);
    // Adjacent stages get distinct colors.
    expect(lines[0]!.color).not.toBe(lines[1]!.color);
  });

  it('skips rest days and stages with fewer than two points', () => {
    const lines = buildStageLines([
      stage({ dayNumber: 1, geometry: geom }),
      stage({ dayNumber: 2, isRestDay: true, geometry: geom }),
      stage({ dayNumber: 3, geometry: [{ lat: 48, lon: 2, ele: 100 }] }),
    ]);
    expect(lines).toHaveLength(1);
  });
});

describe('buildSatelliteStyle / mapStyleFor', () => {
  it('wraps the Esri World Imagery tiles in a raster style', () => {
    const style = buildSatelliteStyle();
    const source = style.sources['esri-world-imagery'];
    expect(source).toMatchObject({ type: 'raster', tiles: [SATELLITE_TILE_URL] });
    expect(style.layers[0]).toMatchObject({ type: 'raster', source: 'esri-world-imagery' });
  });

  it('returns the Positron URL for map and the satellite style object', () => {
    expect(mapStyleFor('map')).toBe(POSITRON_STYLE_URL);
    expect(typeof mapStyleFor('satellite')).toBe('object');
  });
});

describe('collectMarkers', () => {
  it('emits the trip start waypoint, each end waypoint, POIs and accommodations', () => {
    const s = stage({
      startPoint: { lat: 48, lon: 2, ele: 0 },
      endPoint: { lat: 45, lon: 5, ele: 0 },
      startLabel: 'Paris',
      endLabel: 'Lyon',
      resupply: {
        foodAtLunch: [{ name: 'Château', category: 'castle', lat: 46, lon: 3 }],
        waterMorning: null,
        waterAfternoon: null,
        foodAtArrival: [],
      },
      accommodations: [
        { name: 'Gîte', type: 'guest_house', lat: 45.1, lon: 5.1, estimatedPriceMin: 40, estimatedPriceMax: 60, isExactPrice: false, possibleClosed: false, distanceToEndPoint: 0, source: 'osm' },
      ],
    });
    const markers = collectMarkers([s]);
    expect(markers).toEqual([
      { kind: 'waypoint', lon: 2, lat: 48, name: 'Paris' },
      { kind: 'waypoint', lon: 5, lat: 45, name: 'Lyon' },
      { kind: 'poi', lon: 3, lat: 46, name: 'Château' },
      { kind: 'accommodation', lon: 5.1, lat: 45.1, name: 'Gîte' },
    ]);
  });

  it('drops null-island placeholder waypoints', () => {
    expect(collectMarkers([stage()])).toEqual([]);
  });

  it('keeps only the selected accommodation once chosen', () => {
    const s = stage({
      endPoint: { lat: 45, lon: 5, ele: 0 },
      accommodations: [
        { name: 'A', type: 'hotel', lat: 45, lon: 5, estimatedPriceMin: 0, estimatedPriceMax: 0, isExactPrice: false, possibleClosed: false, distanceToEndPoint: 0, source: 'osm' },
        { name: 'B', type: 'hotel', lat: 45, lon: 5, estimatedPriceMin: 0, estimatedPriceMax: 0, isExactPrice: false, possibleClosed: false, distanceToEndPoint: 0, source: 'osm' },
      ],
      selectedAccommodation: { name: 'A', type: 'hotel', lat: 45, lon: 5, estimatedPriceMin: 0, estimatedPriceMax: 0, isExactPrice: false, possibleClosed: false, distanceToEndPoint: 0, source: 'osm' },
    });
    const accommodations = collectMarkers([s]).filter((m) => m.kind === 'accommodation');
    expect(accommodations).toEqual([{ kind: 'accommodation', lon: 5, lat: 45, name: 'A' }]);
  });

  it('emits the start waypoint only for the first stage', () => {
    const a = stage({ startPoint: { lat: 48, lon: 2, ele: 0 }, endPoint: { lat: 45, lon: 5, ele: 0 } });
    const b = stage({ startPoint: { lat: 45, lon: 5, ele: 0 }, endPoint: { lat: 44, lon: 6, ele: 0 } });
    const waypoints = collectMarkers([a, b]).filter((m) => m.kind === 'waypoint');
    // start(a) + end(a) + end(b) — start(b) is NOT re-emitted.
    expect(waypoints).toHaveLength(3);
  });
});

describe('markerCollection', () => {
  it('carries the kind in feature properties', () => {
    const fc = markerCollection([{ kind: 'poi', lon: 3, lat: 46, name: 'X' }]);
    expect(fc.features[0]).toMatchObject({
      properties: { kind: 'poi', name: 'X' },
      geometry: { type: 'Point', coordinates: [3, 46] },
    });
  });
});

describe('alertSegmentToCoords / segmentFeature', () => {
  it('swaps [lat, lon] to [lon, lat]', () => {
    expect(
      alertSegmentToCoords([
        [48, 2],
        [45, 5],
      ]),
    ).toEqual([
      [2, 48],
      [5, 45],
    ]);
  });

  it('builds a LineString only when there are at least two points', () => {
    expect(segmentFeature([[2, 48]]).features).toEqual([]);
    const fc = segmentFeature([
      [2, 48],
      [5, 45],
    ]);
    expect(fc.features[0]).toMatchObject({ geometry: { type: 'LineString' } });
  });
});

describe('applyZoom', () => {
  it('zooms to the current level plus the delta', async () => {
    const zoomTo = jest.fn();
    await applyZoom(() => Promise.resolve(9), zoomTo, 1);
    expect(zoomTo).toHaveBeenCalledWith(10);

    await applyZoom(() => Promise.resolve(9), zoomTo, -1);
    expect(zoomTo).toHaveBeenLastCalledWith(8);
  });

  it('is a no-op before the map has reported a zoom', async () => {
    const zoomTo = jest.fn();
    await applyZoom(() => Promise.resolve(undefined), zoomTo, 1);
    await applyZoom(() => undefined, zoomTo, 1);
    expect(zoomTo).not.toHaveBeenCalled();
  });
});
