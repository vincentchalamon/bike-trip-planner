import { Link } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { fetchTrips, type TripListItem } from '../../src/api/trips';
import { useAuth } from '../../src/auth/store';

export default function Trips() {
  const { logout } = useAuth();
  const [trips, setTrips] = useState<TripListItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      setTrips(await fetchTrips());
    } catch {
      setError('Impossible de charger les voyages.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.title}>Mes voyages</Text>
        <Pressable onPress={() => void logout()}>
          <Text style={styles.logout}>Déconnexion</Text>
        </Pressable>
      </View>
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <FlatList
        data={trips}
        keyExtractor={(item) => item.id ?? String(Math.random())}
        refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} />}
        ListEmptyComponent={<Text style={styles.empty}>Aucun voyage pour le moment.</Text>}
        renderItem={({ item }) => (
          <Link href={{ pathname: '/trip/[id]', params: { id: item.id ?? '' } }} asChild>
            <Pressable style={styles.row}>
              <Text style={styles.rowTitle}>{item.title ?? 'Voyage sans titre'}</Text>
              <Text style={styles.rowMeta}>
                {item.stageCount ?? 0} étapes · {Math.round(item.totalDistance ?? 0)} km ·{' '}
                {item.status ?? 'draft'}
              </Text>
            </Pressable>
          </Link>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 16 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  header: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 },
  title: { fontSize: 20, fontWeight: '700' },
  logout: { color: '#2563eb' },
  error: { color: '#dc2626', marginBottom: 8 },
  empty: { textAlign: 'center', color: '#6b7280', marginTop: 32 },
  row: {
    paddingVertical: 14,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: '#e5e7eb',
  },
  rowTitle: { fontSize: 16, fontWeight: '600' },
  rowMeta: { color: '#6b7280', marginTop: 4 },
});
