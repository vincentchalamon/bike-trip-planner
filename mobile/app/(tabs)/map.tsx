import { useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { fetchTripDetail, fetchTrips, tripCoordinates } from '../../src/api/trips';
import { TripMap } from '../../src/components/TripMap';

export default function MapScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const [coordinates, setCoordinates] = useState<[number, number][]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      setLoading(true);
      setError(null);
      try {
        // Explicit trip if provided, otherwise fall back to the most recent one.
        let tripId = id;
        if (!tripId) {
          const trips = await fetchTrips();
          tripId = trips[0]?.id;
        }
        if (!tripId) {
          setCoordinates([]);
          return;
        }
        const detail = await fetchTripDetail(tripId);
        setCoordinates(detail ? tripCoordinates(detail) : []);
      } catch {
        setError('Impossible de charger le tracé.');
      } finally {
        setLoading(false);
      }
    })();
  }, [id]);

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator />
      </View>
    );
  }

  if (error) {
    return (
      <View style={styles.center}>
        <Text style={styles.error}>{error}</Text>
      </View>
    );
  }

  return <TripMap coordinates={coordinates} />;
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  error: { color: '#dc2626' },
});
