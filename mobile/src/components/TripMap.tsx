import { useEffect, useMemo, useRef } from 'react';
import type { FeatureCollection } from 'geojson';
import {
  Camera,
  type CameraRef,
  GeoJSONSource,
  Layer,
  Map,
  type MapRef,
} from '@maplibre/maplibre-react-native';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Minus, Plus } from './ui/icons';
import { useTheme } from '../theme/context';
import { useMapPrefs } from '../store/map-prefs';
import { useOfflineStore } from '../store/offline-store';
import {
  applyZoom,
  computeBounds,
  mapStyleFor,
  markerCollection,
  poiFromPressFeatures,
  segmentFeature,
  type MapMarker,
  type StageLine,
} from './map/map-utils';

// Pixel inset kept around the fitted route so start/end markers are not flush
// against the viewport edges.
const FIT_PADDING = { top: 48, right: 48, bottom: 48, left: 48 };

export function TripMap({
  stageSegments,
  markers,
  highlightedSegment,
  onSelectPoi,
}: {
  // One colored polyline per stage; the route is drawn stage by stage so each
  // stands out (see stageColor). Camera framing uses all points flattened.
  stageSegments: StageLine[];
  markers?: MapMarker[];
  // Ordered [lon, lat] points of a road stretch to surline (e.g. from an alert
  // `navigate` action). Drawn on its own source/layer above the route. Detail /
  // alerts pilot this later; use alertSegmentToCoords() to adapt an alert
  // action's [lat, lon] segment.
  highlightedSegment?: [number, number][];
  // Tap on a POI marker → the tapped POI (name + coords), so the caller can open
  // the "add to itinerary" popover (#1179). Omitted where the map is read-only:
  // the marker source then carries no onPress and taps are inert.
  onSelectPoi?: (poi: MapMarker) => void;
}) {
  const theme = useTheme();
  const { t } = useTranslation();
  const base = useMapPrefs((s) => s.base);
  const setBase = useMapPrefs((s) => s.setBase);
  const load = useMapPrefs((s) => s.load);
  const isOnline = useOfflineStore((s) => s.isOnline);
  const mapRef = useRef<MapRef>(null);
  const cameraRef = useRef<CameraRef>(null);

  useEffect(() => {
    void load();
  }, [load]);

  // Relative zoom for the +/- controls (see applyZoom).
  const zoomBy = (delta: number) =>
    applyZoom(
      () => mapRef.current?.getZoom(),
      (zoom) => cameraRef.current?.zoomTo(zoom, { duration: 200 }),
      delta,
    );

  // Every object/collection handed to the memoized native components is derived
  // once per input change: a fresh reference on each render would defeat the
  // upstream React.memo (Map/Camera/GeoJSONSource) and force a native re-diff.
  // Offline: fall back to the tile-less style (theme neutral background) so the
  // route + markers still render instead of a broken grid of failed tile
  // requests; back online rebasculates to the normal tiled base.
  const mapStyle = useMemo(
    () => mapStyleFor(base, !isOnline, theme.colors.muted),
    [base, isOnline, theme.colors.muted],
  );
  const lineCoords = useMemo(
    () => stageSegments.flatMap((s) => s.coordinates),
    [stageSegments],
  );
  // Framing / presence coordinates: the route line when there is one, else the
  // markers as a fallback. A rest day carries a single point (no drawable line,
  // so `stageSegments` is empty) but still has its location marker — without the
  // fallback the early return below would hide its detail map entirely (#1142).
  const coordinates = useMemo(
    () =>
      lineCoords.length > 0
        ? lineCoords
        : (markers ?? []).map((m) => [m.lon, m.lat] as [number, number]),
    [lineCoords, markers],
  );
  const bounds = useMemo(() => computeBounds(coordinates), [coordinates]);
  const markerData = useMemo(() => markerCollection(markers ?? []), [markers]);
  const segmentData = useMemo(
    () => segmentFeature(highlightedSegment ?? []),
    [highlightedSegment],
  );
  // One LineString feature per stage, each carrying its color as a data-driven
  // property so a single layer can paint them via ['get', 'color'] (like the web
  // map) instead of one flat single-color line.
  const line = useMemo<FeatureCollection>(
    () => ({
      type: 'FeatureCollection',
      features: stageSegments.map((s) => ({
        type: 'Feature',
        properties: { color: s.color },
        geometry: { type: 'LineString', coordinates: s.coordinates },
      })),
    }),
    [stageSegments],
  );
  // maplibre-react-native v11: `initialViewState` is applied once at native
  // mount, whereas the top-level `CameraStop` `bounds`/`padding` are reactive —
  // feeding `bounds` here re-frames the camera whenever the coordinates change
  // (e.g. prev/next stage on the detail screen, #1074). `bounds` is only null for
  // empty coordinates, which the early return below renders before this is used.
  const cameraStop = useMemo(
    () => ({ bounds: bounds ?? undefined, padding: FIT_PADDING }),
    [bounds],
  );

  if (coordinates.length === 0) {
    return (
      <View style={styles.empty}>
        <Text style={{ color: theme.colors.mutedForeground }}>
          {t('trip.mapEmpty')}
        </Text>
      </View>
    );
  }

  const hasSegment = (highlightedSegment?.length ?? 0) >= 2;

  return (
    <View style={styles.container}>
      <Map ref={mapRef} style={styles.map} mapStyle={mapStyle}>
        <Camera ref={cameraRef} {...cameraStop} />
        <GeoJSONSource id="route" data={line}>
          <Layer
            id="route-line"
            type="line"
            layout={{ 'line-join': 'round', 'line-cap': 'round' }}
            // Per-stage color kept on both bases so stages stay distinguishable
            // over satellite imagery too (matches the web map).
            paint={{
              'line-color': ['get', 'color'],
              'line-width': 4,
            }}
          />
        </GeoJSONSource>
        {hasSegment ? (
          <GeoJSONSource id="segment-highlight" data={segmentData}>
            <Layer
              id="segment-highlight-line"
              type="line"
              layout={{ 'line-join': 'round', 'line-cap': 'round' }}
              paint={{ 'line-color': theme.colors.destructive, 'line-width': 7 }}
            />
          </GeoJSONSource>
        ) : null}
        {markerData.features.length > 0 ? (
          <GeoJSONSource
            id="markers"
            data={markerData}
            {...(onSelectPoi && {
              onPress: (e: { nativeEvent: { features: GeoJSON.Feature[] } }) => {
                const poi = poiFromPressFeatures(e.nativeEvent.features);
                if (poi) onSelectPoi(poi);
              },
            })}
          >
            <Layer
              id="markers-circle"
              type="circle"
              paint={{
                'circle-radius': 6,
                'circle-color': [
                  'match',
                  ['get', 'kind'],
                  // Three visually distinct fills: accentBrand aliases brand to the
                  // same hex, so poi uses accentInk to stay distinguishable from
                  // accommodation (brand) and waypoint (primary).
                  'poi',
                  theme.colors.accentInk,
                  'accommodation',
                  theme.colors.brand,
                  theme.colors.primary,
                ],
                'circle-stroke-width': 2,
                'circle-stroke-color': '#ffffff',
              }}
            />
          </GeoJSONSource>
        ) : null}
      </Map>
      <View
        style={[
          styles.zoom,
          {
            backgroundColor: theme.colors.card,
            borderColor: theme.colors.border,
            ...theme.shadows.soft,
          },
        ]}
      >
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('trip.map.zoomInA11y')}
          onPress={() => void zoomBy(1)}
          // 40x40 is under the 44x44 minimum; grow horizontally (nothing
          // adjacent) and only upward — never across the shared divider into
          // the zoom-out button below, else the hit regions overlap (#1233 a11y).
          hitSlop={{ top: 4, bottom: 0, left: 6, right: 6 }}
          style={styles.zoomButton}
        >
          <Plus size={20} color={theme.colors.foreground} />
        </Pressable>
        <View style={[styles.zoomDivider, { backgroundColor: theme.colors.border }]} />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('trip.map.zoomOutA11y')}
          onPress={() => void zoomBy(-1)}
          // Mirror of zoom-in: extend only downward, away from the divider.
          hitSlop={{ top: 0, bottom: 4, left: 6, right: 6 }}
          style={styles.zoomButton}
        >
          <Minus size={20} color={theme.colors.foreground} />
        </Pressable>
      </View>
      {isOnline ? (
        <View
          accessibilityLabel={t('trip.map.toggleA11y')}
          style={[
            styles.layers,
            {
              backgroundColor: theme.colors.card,
              borderColor: theme.colors.border,
              ...theme.shadows.soft,
            },
          ]}
        >
          {(['map', 'satellite'] as const).map((layer) => {
            const active = base === layer;
            return (
              <Pressable
                key={layer}
                accessibilityRole="button"
                accessibilityState={{ selected: active }}
                accessibilityLabel={
                  layer === 'map'
                    ? t('trip.map.layerMap')
                    : t('trip.map.layerSatellite')
                }
                onPress={() => setBase(layer)}
                // ~29pt tall (padding 6 + 13pt text), under the 44pt minimum.
                // Vertical-only, same reasoning as SegmentedControl: the two
                // segments are flush horizontally (#1233 a11y).
                hitSlop={{ top: 8, bottom: 8 }}
                style={[
                  styles.layerSegment,
                  active && { backgroundColor: theme.colors.brand },
                ]}
              >
                <Text
                  style={{
                    color: active
                      ? theme.colors.primaryForeground
                      : theme.colors.foreground,
                    fontFamily: active
                      ? theme.fonts.sansSemibold
                      : theme.fonts.sansMedium,
                    fontSize: 13,
                  }}
                >
                  {layer === 'map'
                    ? t('trip.map.layerMap')
                    : t('trip.map.layerSatellite')}
                </Text>
              </Pressable>
            );
          })}
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  map: { flex: 1 },
  empty: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  zoom: {
    position: 'absolute',
    top: 12,
    right: 12,
    borderRadius: 12,
    borderWidth: StyleSheet.hairlineWidth,
    overflow: 'hidden',
  },
  zoomButton: {
    width: 40,
    height: 40,
    alignItems: 'center',
    justifyContent: 'center',
  },
  zoomDivider: { height: StyleSheet.hairlineWidth },
  layers: {
    position: 'absolute',
    top: 12,
    left: 12,
    flexDirection: 'row',
    borderRadius: 999,
    borderWidth: StyleSheet.hairlineWidth,
    padding: 3,
  },
  layerSegment: {
    paddingVertical: 6,
    paddingHorizontal: 14,
    borderRadius: 999,
  },
});
