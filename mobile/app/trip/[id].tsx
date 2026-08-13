import { useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { Alert, FlatList, Pressable, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import {
  EmptyState,
  ErrorState,
  LoadingState,
  Screen,
  SegmentedControl,
  type Segment,
} from '../../src/components/ui';
import { Trash2 } from '../../src/components/ui/icons';
import { TripMap } from '../../src/components/TripMap';
import { useTheme } from '../../src/theme';
import { useTripLive } from '../../src/hooks/use-trip-live';
import { useTripStore } from '../../src/store/trip-store';
import { runDeleteStage } from '../../src/store/delete-stage';

type TripView = 'roadbook' | 'map';

export default function TripRoadbook() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useTranslation();
  const theme = useTheme();
  const [view, setView] = useState<TripView>('roadbook');

  // Hydrate the shared store from /detail and keep it live via SSE. The roadbook
  // renders straight from the store, so a stage_updated event reconciled by the
  // core reducers updates the list in place (no ad-hoc local state).
  useTripLive(id);

  const title = useTripStore((s) => s.title);
  const stages = useTripStore((s) => s.stages);
  const isLocked = useTripStore((s) => s.isLocked);
  const loading = useTripStore((s) => s.loading);
  const error = useTripStore((s) => s.error);

  const coordinates = useMemo<[number, number][]>(() => {
    const coords: [number, number][] = [];
    for (const stage of stages) {
      for (const point of stage.geometry ?? []) {
        coords.push([point.lon, point.lat]);
      }
    }
    return coords;
  }, [stages]);

  const segments: readonly Segment<TripView>[] = [
    { value: 'roadbook', label: t('trip.segmentRoadbook') },
    { value: 'map', label: t('trip.segmentMap') },
  ];

  function confirmDelete(index: number): void {
    Alert.alert(t('trip.deleteConfirmTitle'), t('trip.deleteConfirmMessage'), [
      { text: t('trip.cancel'), style: 'cancel' },
      {
        text: t('trip.delete'),
        style: 'destructive',
        onPress: () => {
          // Optimistic delete + rollback are orchestrated in runDeleteStage; the
          // authoritative state comes back over SSE (reconciled by core).
          void runDeleteStage(id, index, useTripStore.getState(), (reason) => {
            Alert.alert(
              reason === 'locked' ? t('trip.lockedTitle') : t('trip.deleteFailedTitle'),
              reason === 'locked' ? t('trip.lockedMessage') : t('trip.deleteFailedMessage'),
            );
          });
        },
      },
    ]);
  }

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
        <ErrorState description={error} />
      </Screen>
    );
  }

  return (
    <Screen padded={false}>
      <View style={{ padding: theme.spacing.base, gap: theme.spacing.md }}>
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.serif,
            fontSize: 24,
          }}
        >
          {title ?? t('trip.title')}
        </Text>
        <SegmentedControl segments={segments} value={view} onChange={setView} />
      </View>

      {view === 'map' ? (
        coordinates.length === 0 ? (
          <EmptyState title={t('trip.mapEmpty')} />
        ) : (
          <TripMap coordinates={coordinates} />
        )
      ) : (
        <FlatList
          data={stages}
          keyExtractor={(_, index) => String(index)}
          ListEmptyComponent={
            <View style={{ height: 300 }}>
              <EmptyState title={t('trip.empty')} />
            </View>
          }
          renderItem={({ item, index }) => (
            <View
              style={{
                flexDirection: 'row',
                alignItems: 'center',
                paddingVertical: theme.spacing.md,
                paddingHorizontal: theme.spacing.base,
                borderBottomWidth: 1,
                borderBottomColor: theme.colors.border,
              }}
            >
              <View style={{ flex: 1 }}>
                <Text
                  style={{
                    color: theme.colors.foreground,
                    fontFamily: theme.fonts.sansSemibold,
                    fontSize: 16,
                  }}
                >
                  {t('trip.day', { day: item.dayNumber ?? '?' })}
                  {item.isRestDay ? ` · ${t('trip.rest')}` : ''}
                </Text>
                <Text
                  style={{
                    color: theme.colors.mutedForeground,
                    fontFamily: theme.fonts.sans,
                    fontSize: 14,
                    marginTop: 2,
                  }}
                >
                  {item.startLabel ?? '?'} → {item.endLabel ?? item.label ?? '?'}
                </Text>
                <Text
                  style={{
                    color: theme.colors.mutedForeground,
                    fontFamily: theme.fonts.mono,
                    fontSize: 13,
                    marginTop: 2,
                  }}
                >
                  {t('trip.stageMeta', {
                    distance: Math.round(item.distance ?? 0),
                    elevation: Math.round(item.elevation ?? 0),
                  })}
                </Text>
              </View>
              {/* A started trip is read-only (423); hide the action entirely. */}
              {!isLocked ? (
                <Pressable
                  accessibilityLabel={t('trip.deleteA11y', { day: item.dayNumber ?? index + 1 })}
                  hitSlop={8}
                  onPress={() => confirmDelete(index)}
                  style={{ padding: theme.spacing.sm }}
                >
                  <Trash2 color={theme.colors.destructive} size={20} />
                </Pressable>
              ) : null}
            </View>
          )}
        />
      )}
    </Screen>
  );
}
