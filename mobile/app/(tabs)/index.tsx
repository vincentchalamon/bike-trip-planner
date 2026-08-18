import { useRouter } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { confirmDeleteTrip, useTrips } from '../../src/hooks/use-trips';
import type { TripListItem } from '../../src/api/trips';
import {
  EmptyState,
  ErrorState,
  Input,
  ListRow,
  LoadingState,
  Screen,
} from '../../src/components/ui';
import { Copy, Inbox, Search, Trash2 } from '../../src/components/ui/icons';
import { useTheme } from '../../src/theme';

export default function Trips() {
  const { t } = useTranslation();
  const theme = useTheme();
  const router = useRouter();
  const {
    trips,
    loading,
    loadingMore,
    refreshing,
    error,
    title,
    startDate,
    endDate,
    hasActiveFilter,
    canLoadMore,
    setTitle,
    setStartDate,
    setEndDate,
    reload,
    loadMore,
    remove,
    duplicate,
  } = useTrips();

  // Id of the trip whose duplication is in flight — guards against a double-tap
  // firing two POST /trips/{id}/duplicate (each would clone the trip again).
  const [duplicatingId, setDuplicatingId] = useState<string | null>(null);

  function onDelete(item: TripListItem): void {
    confirmDeleteTrip({
      title: t('trips.deleteConfirmTitle'),
      message: t('trips.deleteConfirmMessage', {
        title: item.title ?? t('trips.untitled'),
      }),
      cancel: t('trips.cancel'),
      confirm: t('trips.delete'),
      onConfirm: () => {
        void remove(item.id ?? '');
      },
    });
  }

  async function onDuplicate(item: TripListItem): Promise<void> {
    const id = item.id ?? '';
    if (duplicatingId === id) return; // re-entrance guard: a duplication is in flight
    setDuplicatingId(id);
    const newId = await duplicate(id);
    setDuplicatingId(null);
    if (!newId) {
      Alert.alert(t('trips.duplicateFailedTitle'), t('trips.duplicateFailed'));
    }
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

      <View style={{ paddingHorizontal: theme.spacing.base, gap: theme.spacing.sm }}>
        <Input
          value={title}
          onChangeText={setTitle}
          placeholder={t('trips.searchPlaceholder')}
          autoCapitalize="none"
          autoCorrect={false}
        />
        <View style={{ flexDirection: 'row', gap: theme.spacing.sm }}>
          <View style={{ flex: 1 }}>
            <Input
              value={startDate}
              onChangeText={setStartDate}
              placeholder={t('trips.startDate')}
              autoCapitalize="none"
              autoCorrect={false}
            />
          </View>
          <View style={{ flex: 1 }}>
            <Input
              value={endDate}
              onChangeText={setEndDate}
              placeholder={t('trips.endDate')}
              autoCapitalize="none"
              autoCorrect={false}
            />
          </View>
        </View>
      </View>

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState
          title={t('common.error')}
          description={t('trips.error')}
          onRetry={reload}
          retryLabel={t('common.retry')}
        />
      ) : (
        <FlatList
          data={trips}
          keyExtractor={(item) => item.id ?? String(Math.random())}
          contentContainerStyle={{ paddingTop: theme.spacing.sm }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={reload} />}
          onEndReachedThreshold={0.4}
          onEndReached={() => {
            if (canLoadMore) loadMore();
          }}
          ListEmptyComponent={
            <View style={{ height: 360 }}>
              <EmptyState
                title={hasActiveFilter ? t('trips.noResults') : t('trips.empty')}
                description={hasActiveFilter ? t('trips.noResultsHint') : undefined}
                icon={
                  hasActiveFilter ? (
                    <Search color={theme.colors.mutedIcon} size={40} />
                  ) : (
                    <Inbox color={theme.colors.mutedIcon} size={40} />
                  )
                }
              />
            </View>
          }
          ListFooterComponent={
            loadingMore ? (
              <View style={{ paddingVertical: theme.spacing.base }}>
                <ActivityIndicator color={theme.colors.mutedIcon} />
              </View>
            ) : null
          }
          renderItem={({ item }) => (
            <ListRow
              title={item.title ?? t('trips.untitled')}
              subtitle={t('trips.meta', {
                stages: item.stageCount ?? 0,
                distance: Math.round(item.totalDistance ?? 0),
                status: item.status ?? 'draft',
              })}
              right={
                <View style={{ flexDirection: 'row', gap: theme.spacing.xs }}>
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel={t('trips.duplicateA11y', {
                      title: item.title ?? t('trips.untitled'),
                    })}
                    accessibilityState={{ disabled: duplicatingId === item.id }}
                    disabled={duplicatingId === item.id}
                    hitSlop={8}
                    onPress={() => void onDuplicate(item)}
                    style={{ padding: theme.spacing.xs }}
                  >
                    <Copy color={theme.colors.mutedIcon} size={20} />
                  </Pressable>
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel={t('trips.deleteA11y', {
                      title: item.title ?? t('trips.untitled'),
                    })}
                    hitSlop={8}
                    onPress={() => onDelete(item)}
                    style={{ padding: theme.spacing.xs }}
                  >
                    <Trash2 color={theme.colors.mutedIcon} size={20} />
                  </Pressable>
                </View>
              }
              onPress={() =>
                router.push({ pathname: '/trip/[id]', params: { id: item.id ?? '' } })
              }
            />
          )}
        />
      )}
    </Screen>
  );
}
