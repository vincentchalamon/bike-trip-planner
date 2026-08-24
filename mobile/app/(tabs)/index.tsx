import { useRouter } from 'expo-router';
import { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import Svg, { Path } from 'react-native-svg';
import { useTranslation } from 'react-i18next';
import { confirmDeleteTrip, useTrips } from '../../src/hooks/use-trips';
import { useLocalNotifications } from '../../src/hooks/use-local-notifications';
import type { TripListItem } from '../../src/api/trips';
import {
  DateField,
  EmptyState,
  ErrorState,
  LoadingState,
  Screen,
} from '../../src/components/ui';
import { Copy, Inbox, Search, Trash2 } from '../../src/components/ui/icons';
import { useTheme, type Theme } from '../../src/theme';
import { formatTripDateRange } from '../../src/lib/dates';
import { badgeColors, statusOf, type TripStatus } from '../../src/screens/trips-list';

// Decorative, data-free vignette: a neutral surface with a faint winding line
// standing in for a route trace (the trip list carries no geometry).
function RouteThumbnail({ theme }: { theme: Theme }) {
  return (
    <Svg width="100%" height="100%" viewBox="0 0 112 96" preserveAspectRatio="xMidYMid slice">
      <Path
        d="M8 78 C 26 70, 24 44, 44 40 S 74 48, 84 30 S 100 16, 106 12"
        stroke={theme.colors.mutedIcon}
        strokeWidth={2.5}
        strokeLinecap="round"
        fill="none"
        opacity={0.5}
      />
      <Path
        d="M8 88 C 30 84, 40 66, 58 62 S 90 66, 106 52"
        stroke={theme.colors.mutedIcon}
        strokeWidth={1.5}
        strokeLinecap="round"
        fill="none"
        opacity={0.25}
      />
    </Svg>
  );
}

function StatusBadge({ theme, status, label }: { theme: Theme; status: TripStatus; label: string }) {
  const c = badgeColors(theme, status);
  return (
    <View
      style={{
        position: 'absolute',
        left: theme.spacing.xs,
        bottom: theme.spacing.xs,
        paddingHorizontal: theme.spacing.sm,
        paddingVertical: 2,
        borderRadius: theme.radius.full,
        borderWidth: StyleSheet.hairlineWidth,
        backgroundColor: c.bg,
        borderColor: c.border,
      }}
    >
      <Text style={{ color: c.fg, fontFamily: theme.fonts.sansMedium, fontSize: 11 }}>{label}</Text>
    </View>
  );
}

interface TripCardProps {
  item: TripListItem;
  theme: Theme;
  duplicatingId: string | null;
  onOpen: () => void;
  onDelete: () => void;
  onDuplicate: () => void;
  duplicateA11y: string;
  deleteA11y: string;
  statusLabel: string;
  title: string;
  subtitle: string;
}

function TripCard({
  item,
  theme,
  duplicatingId,
  onOpen,
  onDelete,
  onDuplicate,
  duplicateA11y,
  deleteA11y,
  statusLabel,
  title,
  subtitle,
}: TripCardProps) {
  const duplicating = duplicatingId === item.id;
  const iconBtn = {
    width: 30,
    height: 30,
    borderRadius: theme.radius.full,
    alignItems: 'center' as const,
    justifyContent: 'center' as const,
    backgroundColor: theme.colors.card,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: theme.colors.border,
    ...theme.shadows.soft,
  };

  return (
    <Pressable
      accessibilityRole="button"
      onPress={onOpen}
      style={({ pressed }) => ({
        overflow: 'hidden',
        borderRadius: theme.radius.xl,
        borderWidth: StyleSheet.hairlineWidth,
        borderColor: theme.colors.border,
        backgroundColor: pressed ? theme.colors.muted : theme.colors.card,
        ...theme.shadows.soft,
      })}
    >
      <View
        style={{
          height: 92,
          width: '100%',
          backgroundColor: theme.colors.secondary,
        }}
      >
        <RouteThumbnail theme={theme} />
        <StatusBadge theme={theme} status={statusOf(item)} label={statusLabel} />
        <View
          style={{
            position: 'absolute',
            top: theme.spacing.xs,
            right: theme.spacing.xs,
            flexDirection: 'row',
            gap: theme.spacing.xs,
          }}
        >
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={duplicateA11y}
            accessibilityState={{ disabled: duplicating }}
            disabled={duplicating}
            hitSlop={8}
            onPress={onDuplicate}
            style={iconBtn}
          >
            <Copy color={theme.colors.mutedForeground} size={16} />
          </Pressable>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={deleteA11y}
            hitSlop={8}
            onPress={onDelete}
            style={iconBtn}
          >
            <Trash2 color={theme.colors.destructive} size={16} />
          </Pressable>
        </View>
      </View>

      <View style={{ padding: theme.spacing.md }}>
        <Text
          numberOfLines={2}
          style={{ color: theme.colors.foreground, fontFamily: theme.fonts.serif, fontSize: 16 }}
        >
          {title}
        </Text>
        <Text
          numberOfLines={2}
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 13,
            marginTop: theme.spacing.xs,
          }}
        >
          {subtitle}
        </Text>
      </View>
    </Pressable>
  );
}

export default function Trips() {
  const { t, i18n } = useTranslation();
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

  // Re-plan the on-device local notifications (offline-not-ready, trip-without-date)
  // off the loaded trip list, gated by the per-category toggles.
  useLocalNotifications(trips);

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

  function subtitleOf(item: TripListItem): string {
    const parts = [
      formatTripDateRange(item.startDate, item.endDate, i18n.language, {
        separator: ' – ',
        month: 'short',
        startStyle: 'dayMonth',
      }),
      t('trips.stagesCount', { stages: item.stageCount ?? 0 }),
      t('trips.distanceKm', { distance: Math.round(item.totalDistance ?? 0) }),
    ].filter(Boolean);
    return parts.join(' · ');
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
        <View
          style={{
            flexDirection: 'row',
            alignItems: 'center',
            height: 44,
            paddingHorizontal: theme.spacing.md,
            gap: theme.spacing.sm,
            borderWidth: StyleSheet.hairlineWidth,
            borderColor: theme.colors.input,
            borderRadius: theme.radius.md,
            backgroundColor: theme.colors.surface,
          }}
        >
          <Search color={theme.colors.mutedForeground} size={18} />
          <TextInput
            value={title}
            onChangeText={setTitle}
            placeholder={t('trips.searchPlaceholder')}
            placeholderTextColor={theme.colors.mutedForeground}
            autoCapitalize="none"
            autoCorrect={false}
            style={{
              flex: 1,
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sans,
              fontSize: 16,
            }}
          />
        </View>
        <View style={{ flexDirection: 'row', gap: theme.spacing.sm }}>
          <View style={{ flex: 1 }}>
            <DateField
              label={t('trips.fromLabel')}
              value={startDate}
              onChange={setStartDate}
              placeholder={t('trips.startDate')}
              accessibilityLabel={t('trips.fromLabel')}
              clearLabel={t('trips.clearDate')}
            />
          </View>
          <View style={{ flex: 1 }}>
            <DateField
              label={t('trips.toLabel')}
              value={endDate}
              onChange={setEndDate}
              placeholder={t('trips.endDate')}
              accessibilityLabel={t('trips.toLabel')}
              clearLabel={t('trips.clearDate')}
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
          keyExtractor={(item, index) => item.id ?? String(index)}
          contentContainerStyle={{
            padding: theme.spacing.base,
            paddingTop: theme.spacing.md,
            gap: theme.spacing.md,
          }}
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
          renderItem={({ item }) => {
            const displayTitle = item.title ?? t('trips.untitled');
            return (
              <TripCard
                item={item}
                theme={theme}
                duplicatingId={duplicatingId}
                title={displayTitle}
                subtitle={subtitleOf(item)}
                statusLabel={t(`trips.status.${statusOf(item)}`)}
                duplicateA11y={t('trips.duplicateA11y', { title: displayTitle })}
                deleteA11y={t('trips.deleteA11y', { title: displayTitle })}
                onOpen={() =>
                  router.push({ pathname: '/trip/[id]', params: { id: item.id ?? '' } })
                }
                onDelete={() => onDelete(item)}
                onDuplicate={() => void onDuplicate(item)}
              />
            );
          }}
        />
      )}
    </Screen>
  );
}
