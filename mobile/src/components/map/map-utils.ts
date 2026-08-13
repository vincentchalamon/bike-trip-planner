import type { StageData } from '@btp/core';
import type { StyleSpecification } from '@maplibre/maplibre-react-native';
import type { FeatureCollection } from 'geojson';

// The two base maps the map tab can render. Persisted via the map-prefs store.
export type MapBase = 'map' | 'satellite';

// Carto Positron — same vector style the web frontend uses.
export const POSITRON_STYLE_URL =
  'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json';

export const SATELLITE_TILE_URL =
  'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';

// Esri World Imagery raster tiles, wrapped in a minimal MapLibre style so the
// satellite base needs no vendor style.json dependency.
export function buildSatelliteStyle(): StyleSpecification {
  return {
    version: 8,
    sources: {
      'esri-world-imagery': {
        type: 'raster',
        tiles: [SATELLITE_TILE_URL],
        tileSize: 256,
        attribution: 'Esri, Maxar, Earthstar Geographics',
      },
    },
    layers: [
      { id: 'esri-world-imagery', type: 'raster', source: 'esri-world-imagery' },
    ],
  };
}

export function mapStyleFor(base: MapBase): string | StyleSpecification {
  return base === 'satellite' ? buildSatelliteStyle() : POSITRON_STYLE_URL;
}

// Bounding box of all coordinates as [west, south, east, north] (MapLibre's
// LngLatBounds), or null when there is nothing to frame. Feeds the Camera so it
// fits the whole route rather than a fixed center/zoom.
export function computeBounds(
  coords: [number, number][],
): [number, number, number, number] | null {
  if (coords.length === 0) return null;
  let west = Infinity;
  let south = Infinity;
  let east = -Infinity;
  let north = -Infinity;
  for (const [lon, lat] of coords) {
    if (lon < west) west = lon;
    if (lon > east) east = lon;
    if (lat < south) south = lat;
    if (lat > north) north = lat;
  }
  return [west, south, east, north];
}

export type MarkerKind = 'waypoint' | 'poi' | 'accommodation';

export interface MapMarker {
  kind: MarkerKind;
  lon: number;
  lat: number;
  name: string;
}

// The store seeds missing endpoints with {lat:0, lon:0} (see stageDataFromDetail
// and the rest-day placeholder), so drop null-island points to avoid a marker in
// the Gulf of Guinea.
function isPlaceholder(lat: number, lon: number): boolean {
  return lat === 0 && lon === 0;
}

// Flatten the stages into map markers: the trip start waypoint, every stage end
// waypoint, all POIs, and each stage's accommodations (the selected one alone
// once a choice is made). Icons/colours are applied by the map layer, keyed on
// `kind`.
export function collectMarkers(stages: StageData[]): MapMarker[] {
  const markers: MapMarker[] = [];
  stages.forEach((stage, i) => {
    const start = stage.startPoint;
    if (i === 0 && start && !isPlaceholder(start.lat, start.lon)) {
      markers.push({
        kind: 'waypoint',
        lon: start.lon,
        lat: start.lat,
        name: stage.startLabel ?? '',
      });
    }
    const end = stage.endPoint;
    if (end && !isPlaceholder(end.lat, end.lon)) {
      markers.push({
        kind: 'waypoint',
        lon: end.lon,
        lat: end.lat,
        name: stage.endLabel ?? '',
      });
    }
    for (const poi of stage.pois) {
      markers.push({ kind: 'poi', lon: poi.lon, lat: poi.lat, name: poi.name });
    }
    const accommodations = stage.selectedAccommodation
      ? [stage.selectedAccommodation]
      : stage.accommodations;
    for (const acc of accommodations) {
      markers.push({
        kind: 'accommodation',
        lon: acc.lon,
        lat: acc.lat,
        name: acc.name,
      });
    }
  });
  return markers;
}

export function markerCollection(markers: MapMarker[]): FeatureCollection {
  return {
    type: 'FeatureCollection',
    features: markers.map((m) => ({
      type: 'Feature',
      properties: { kind: m.kind, name: m.name },
      geometry: { type: 'Point', coordinates: [m.lon, m.lat] },
    })),
  };
}

// Convert an alert action `segment` (list of [lat, lon] tuples, core #982) to the
// [lon, lat] order MapLibre expects. Callers (stage detail / alerts) feed the
// result to TripMap's `highlightedSegment` prop.
export function alertSegmentToCoords(
  segment: [number, number][],
): [number, number][] {
  return segment.map(([lat, lon]) => [lon, lat]);
}

// A one-LineString FeatureCollection for the highlighted stretch, or an empty
// collection when there is nothing (or too little) to draw.
export function segmentFeature(coords: [number, number][]): FeatureCollection {
  return {
    type: 'FeatureCollection',
    features:
      coords.length >= 2
        ? [
            {
              type: 'Feature',
              properties: {},
              geometry: { type: 'LineString', coordinates: coords },
            },
          ]
        : [],
  };
}
