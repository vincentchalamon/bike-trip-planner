import { useMemo } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useTripStore } from '../../src/store/trip-store';
import { TripMap } from '../../src/components/TripMap';

export default function MapScreen() {
  // Read the route straight from the shared store (hydrated + kept live by the
  // roadbook via SSE); no independent fetch (#1014).
  const stages = useTripStore((s) => s.stages);

  const coordinates = useMemo<[number, number][]>(() => {
    const coords: [number, number][] = [];
    for (const stage of stages) {
      for (const point of stage.geometry ?? []) {
        coords.push([point.lon, point.lat]);
      }
    }
    return coords;
  }, [stages]);

  if (coordinates.length === 0) {
    return (
      <View style={styles.center}>
        <Text style={styles.empty}>Ouvrez un voyage pour voir son tracé.</Text>
      </View>
    );
  }

  return <TripMap coordinates={coordinates} />;
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  empty: { color: '#6b7280', textAlign: 'center' },
});
