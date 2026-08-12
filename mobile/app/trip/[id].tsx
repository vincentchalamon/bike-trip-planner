import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { fetchTripDetail, type Stage, type TripDetail } from '../../src/api/trips';

export default function TripRoadbook() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const [detail, setDetail] = useState<TripDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      try {
        setDetail(await fetchTripDetail(id));
      } catch {
        setError('Impossible de charger le roadbook.');
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

  if (error || !detail) {
    return (
      <View style={styles.center}>
        <Text style={styles.error}>{error ?? 'Voyage introuvable.'}</Text>
      </View>
    );
  }

  const stages: Stage[] = detail.stages ?? [];

  return (
    <View style={styles.container}>
      <Text style={styles.title}>{detail.title ?? 'Roadbook'}</Text>
      <Pressable style={styles.mapButton} onPress={() => router.push({ pathname: '/(tabs)/map', params: { id } })}>
        <Text style={styles.mapButtonText}>Voir sur la carte</Text>
      </Pressable>
      <FlatList
        data={stages}
        keyExtractor={(_, index) => String(index)}
        ListEmptyComponent={<Text style={styles.empty}>Aucune étape calculée.</Text>}
        renderItem={({ item }) => (
          <View style={styles.row}>
            <Text style={styles.day}>
              Jour {item.dayNumber ?? '?'}
              {item.isRestDay ? ' · repos' : ''}
            </Text>
            <Text style={styles.label}>
              {item.startLabel ?? '?'} → {item.endLabel ?? item.label ?? '?'}
            </Text>
            <Text style={styles.meta}>
              {Math.round(item.distance ?? 0)} km · +{Math.round(item.elevation ?? 0)} m
            </Text>
          </View>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  title: { fontSize: 22, fontWeight: '700', marginBottom: 12 },
  mapButton: {
    backgroundColor: '#2563eb',
    borderRadius: 8,
    paddingVertical: 10,
    alignItems: 'center',
    marginBottom: 16,
  },
  mapButtonText: { color: '#fff', fontWeight: '600' },
  row: {
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: '#e5e7eb',
  },
  day: { fontSize: 16, fontWeight: '600' },
  label: { color: '#374151', marginTop: 2 },
  meta: { color: '#6b7280', marginTop: 2 },
  error: { color: '#dc2626' },
  empty: { textAlign: 'center', color: '#6b7280', marginTop: 32 },
});
