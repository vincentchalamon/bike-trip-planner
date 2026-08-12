import type { FeatureCollection } from 'geojson';
import { Camera, GeoJSONSource, Layer, Map } from '@maplibre/maplibre-react-native';
import { StyleSheet, Text, View } from 'react-native';

// Same Carto Positron style the web frontend uses.
const STYLE_URL = 'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json';

function centerOf(coords: [number, number][]): [number, number] {
  const sum = coords.reduce<[number, number]>(
    (acc, [lon, lat]) => [acc[0] + lon, acc[1] + lat],
    [0, 0],
  );
  return [sum[0] / coords.length, sum[1] / coords.length];
}

export function TripMap({ coordinates }: { coordinates: [number, number][] }) {
  if (coordinates.length === 0) {
    return (
      <View style={styles.empty}>
        <Text>Aucun tracé disponible pour ce voyage.</Text>
      </View>
    );
  }

  const line: FeatureCollection = {
    type: 'FeatureCollection',
    features: [
      {
        type: 'Feature',
        properties: {},
        geometry: { type: 'LineString', coordinates },
      },
    ],
  };

  return (
    <Map style={styles.map} mapStyle={STYLE_URL}>
      <Camera initialViewState={{ center: centerOf(coordinates), zoom: 8 }} />
      <GeoJSONSource id="route" data={line}>
        <Layer
          id="route-line"
          type="line"
          layout={{ 'line-join': 'round', 'line-cap': 'round' }}
          paint={{ 'line-color': '#2563eb', 'line-width': 4 }}
        />
      </GeoJSONSource>
    </Map>
  );
}

const styles = StyleSheet.create({
  map: { flex: 1 },
  empty: { flex: 1, alignItems: 'center', justifyContent: 'center' },
});
