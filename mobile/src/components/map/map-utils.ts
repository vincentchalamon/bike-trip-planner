import type { StageData } from '@btp/core';
import { resupplyPois } from '@btp/core';
import type { StyleSpecification } from '@maplibre/maplibre-react-native';
import type { FeatureCollection } from 'geojson';
import { stageColor } from './stage-colors';

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

// Offline fallback style: a single flat background layer and no network tile
// source, so the map still mounts and paints the local GeoJSON sources (route +
// markers, added as children by TripMap) instead of a broken grey grid of failed
// tile requests. `background` comes from the mobile theme (never a raw hex).
export function buildOfflineStyle(background: string): StyleSpecification {
  return {
    version: 8,
    sources: {},
    layers: [
      { id: 'offline-background', type: 'background', paint: { 'background-color': background } },
    ],
  };
}

// Resolve the MapLibre style for the current base. Offline (offline-store
// isOnline === false) drops the network-tiled Positron/satellite bases for the
// tile-less offline style; the local GeoJSON sources still render on top. Back
// online rebasculates to the normal tiled style.
export function mapStyleFor(
  base: MapBase,
  offline = false,
  offlineBackground = 'transparent',
): string | StyleSpecification {
  if (offline) return buildOfflineStyle(offlineBackground);
  return base === 'satellite' ? buildSatelliteStyle() : POSITRON_STYLE_URL;
}

// Relative zoom step for the +/- controls: read the live zoom off the map and
// animate the camera one step in/out. No-op until the native map has mounted and
// reported a zoom (getZoom resolves null/undefined). Extracted from TripMap so
// the ref orchestration is unit-testable without a native map.
export async function applyZoom(
  getZoom: () => Promise<number | undefined> | number | undefined,
  zoomTo: (zoom: number) => void,
  delta: number,
): Promise<void> {
  const current = await getZoom();
  if (current == null) return;
  zoomTo(current + delta);
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

// One drawable stage: its ordered [lon, lat] geometry and the color that sets
// it apart from its neighbours on the map (see stageColor).
export interface StageLine {
  color: string;
  coordinates: [number, number][];
}

// Split the route into one colored polyline per stage (mirrors the web map).
// Rest days and stages too short to draw (< 2 points) are skipped, exactly like
// the web's buildRouteGeoJSON; the color keys on the stable 1-based `dayNumber`.
export function buildStageLines(stages: StageData[]): StageLine[] {
  return stages
    .filter((s) => !s.isRestDay && (s.geometry?.length ?? 0) >= 2)
    .map((s) => ({
      color: stageColor(s.dayNumber),
      coordinates: s.geometry.map((p) => [p.lon, p.lat] as [number, number]),
    }));
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
    for (const poi of resupplyPois(stage.resupply)) {
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

// Pick the tapped POI out of a marker-source press event's features (the ordered
// GeoJSON features under the touch, highest z-index first). Only POI markers are
// addable as a route waypoint (#1179), so accommodation/waypoint taps resolve to
// null and no popover opens. Kept pure so TripMap's native onPress stays a
// one-liner and this is unit-testable without a native map.
export function poiFromPressFeatures(
  features: GeoJSON.Feature[] | undefined,
): MapMarker | null {
  const poi = (features ?? []).find(
    (f) => f.properties?.kind === 'poi' && f.geometry?.type === 'Point',
  );
  if (!poi || poi.geometry.type !== 'Point') return null;
  const [lon, lat] = poi.geometry.coordinates as [number, number];
  return { kind: 'poi', lon, lat, name: String(poi.properties?.name ?? '') };
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
