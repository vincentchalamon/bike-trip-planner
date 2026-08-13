import { useRouter } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { FlatList, RefreshControl, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { fetchTrips, type TripListItem } from '../../src/api/trips';
import {
  EmptyState,
  ErrorState,
  ListRow,
  LoadingState,
  Screen,
} from '../../src/components/ui';
import { ChevronRight, Inbox } from '../../src/components/ui/icons';
import { useTheme } from '../../src/theme';

export default function Trips() {
  const { t } = useTranslation();
  const theme = useTheme();
  const router = useRouter();
  const [trips, setTrips] = useState<TripListItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      setTrips(await fetchTrips());
    } catch {
      setError(t('trips.error'));
    } finally {
      setLoading(false);
    }
  }, [t]);

  useEffect(() => {
    void load();
  }, [load]);

  if (loading) {
    return (
      <Screen padded={false}>
        <LoadingState />
      </Screen>
    );
  }

  if (error) {
    return (
      <Screen padded={false}>
        <ErrorState
          title={t('common.error')}
          description={error}
          onRetry={() => void load()}
          retryLabel={t('common.retry')}
        />
      </Screen>
    );
  }

  return (
    <Screen padded={false}>
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.serif,
          fontSize: 26,
          paddingHorizontal: theme.spacing.base,
          paddingTop: theme.spacing.base,
          paddingBottom: theme.spacing.sm,
        }}
      >
        {t('trips.title')}
      </Text>
      <FlatList
        data={trips}
        keyExtractor={(item) => item.id ?? String(Math.random())}
        refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} />}
        ListEmptyComponent={
          <View style={{ height: 400 }}>
            <EmptyState
              title={t('trips.empty')}
              icon={<Inbox color={theme.colors.mutedIcon} size={40} />}
            />
          </View>
        }
        renderItem={({ item }) => (
          <ListRow
            title={item.title ?? t('trips.untitled')}
            subtitle={t('trips.meta', {
              stages: item.stageCount ?? 0,
              distance: Math.round(item.totalDistance ?? 0),
              status: item.status ?? 'draft',
            })}
            right={<ChevronRight color={theme.colors.mutedIcon} size={20} />}
            onPress={() =>
              router.push({ pathname: '/trip/[id]', params: { id: item.id ?? '' } })
            }
          />
        )}
      />
    </Screen>
  );
}
