import { useEffect, useMemo } from 'react';
import type { FeatureCollection } from 'geojson';
import { Camera, GeoJSONSource, Layer, Map } from '@maplibre/maplibre-react-native';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { useTheme } from '../theme/context';
import { useMapPrefs } from '../store/map-prefs';
import {
  computeBounds,
  mapStyleFor,
  markerCollection,
  segmentFeature,
  type MapMarker,
} from './map/map-utils';

// Pixel inset kept around the fitted route so start/end markers are not flush
// against the viewport edges.
const FIT_PADDING = { top: 48, right: 48, bottom: 48, left: 48 };

export function TripMap({
  coordinates,
  markers,
  highlightedSegment,
}: {
  coordinates: [number, number][];
  markers?: MapMarker[];
  // Ordered [lon, lat] points of a road stretch to surline (e.g. from an alert
  // `navigate` action). Drawn on its own source/layer above the route. Detail /
  // alerts pilot this later; use alertSegmentToCoords() to adapt an alert
  // action's [lat, lon] segment.
  highlightedSegment?: [number, number][];
}) {
  const theme = useTheme();
  const { t } = useTranslation();
  const base = useMapPrefs((s) => s.base);
  const toggle = useMapPrefs((s) => s.toggle);
  const load = useMapPrefs((s) => s.load);

  useEffect(() => {
    void load();
  }, [load]);

  // Every object/collection handed to the memoized native components is derived
  // once per input change: a fresh reference on each render would defeat the
  // upstream React.memo (Map/Camera/GeoJSONSource) and force a native re-diff.
  const mapStyle = useMemo(() => mapStyleFor(base), [base]);
  const bounds = useMemo(() => computeBounds(coordinates), [coordinates]);
  const markerData = useMemo(() => markerCollection(markers ?? []), [markers]);
  const segmentData = useMemo(
    () => segmentFeature(highlightedSegment ?? []),
    [highlightedSegment],
  );
  const line = useMemo<FeatureCollection>(
    () => ({
      type: 'FeatureCollection',
      features: [
        {
          type: 'Feature',
          properties: {},
          geometry: { type: 'LineString', coordinates },
        },
      ],
    }),
    [coordinates],
  );
  // maplibre-react-native v11: `initialViewState` is applied once at native
  // mount, whereas the top-level `CameraStop` props (bounds/padding, center/zoom)
  // are reactive — feeding `bounds` here re-frames the camera whenever the
  // coordinates change (e.g. prev/next stage on the detail screen, #1074).
  const cameraStop = useMemo(
    () =>
      bounds
        ? { bounds, padding: FIT_PADDING }
        : { center: coordinates[0], zoom: 8 },
    [bounds, coordinates],
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

  const satellite = base === 'satellite';
  const hasSegment = (highlightedSegment?.length ?? 0) >= 2;

  return (
    <View style={styles.container}>
      <Map style={styles.map} mapStyle={mapStyle}>
        <Camera {...cameraStop} />
        <GeoJSONSource id="route" data={line}>
          <Layer
            id="route-line"
            type="line"
            layout={{ 'line-join': 'round', 'line-cap': 'round' }}
            paint={{
              'line-color': satellite ? '#ffffff' : theme.colors.brand,
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
          <GeoJSONSource id="markers" data={markerData}>
            <Layer
              id="markers-circle"
              type="circle"
              paint={{
                'circle-radius': 6,
                'circle-color': [
                  'match',
                  ['get', 'kind'],
                  'poi',
                  theme.colors.accentBrand,
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
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={t('trip.map.toggleA11y')}
        onPress={toggle}
        style={[
          styles.toggle,
          { backgroundColor: theme.colors.card, borderColor: theme.colors.border },
        ]}
      >
        <Text
          style={[
            styles.toggleText,
            { color: theme.colors.foreground, fontFamily: theme.fonts.sansMedium },
          ]}
        >
          {satellite ? t('trip.map.layerMap') : t('trip.map.layerSatellite')}
        </Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  map: { flex: 1 },
  empty: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  toggle: {
    position: 'absolute',
    top: 12,
    right: 12,
    paddingVertical: 8,
    paddingHorizontal: 14,
    borderRadius: 999,
    borderWidth: StyleSheet.hairlineWidth,
  },
  toggleText: { fontSize: 13 },
});
