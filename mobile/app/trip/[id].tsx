import { useLocalSearchParams, useRouter } from 'expo-router';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { useTripLive } from '../../src/hooks/use-trip-live';
import { useTripStore } from '../../src/store/trip-store';

export default function TripRoadbook() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();

  // Hydrate the shared store from /detail and keep it live via SSE. The roadbook
  // renders straight from the store, so a stage_updated event reconciled by the
  // core reducers updates the list in place (no ad-hoc local state).
  useTripLive(id);

  const title = useTripStore((s) => s.title);
  const stages = useTripStore((s) => s.stages);
  const loading = useTripStore((s) => s.loading);
  const error = useTripStore((s) => s.error);

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

  return (
    <View style={styles.container}>
      <Text style={styles.title}>{title ?? 'Roadbook'}</Text>
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
