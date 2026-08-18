import { useCallback, useRef, useState } from 'react';
import { Alert, FlatList, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../ui';
import { StageCard, stageKey } from './StageCard';
import { RoadbookBanner } from './RoadbookBanner';
import {
  isStageToday,
  stageDateFor,
  summaryColorKey,
  todayUtc,
  tripStateFromDates,
} from './roadbook-dates';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';
import type { MutationFailure } from '../../store/gating';
import { useTripMutations } from '../../hooks/use-trip-mutations';

// The roadbook tab: a lifecycle summary header (upcoming / ongoing / past),
// the lock / out-of-zone / no-dates banners, then the stage list (StageCard
// rows) with per-stage dates and an "Aujourd'hui" pastille. #1039 wires each
// row's tap-through to the stage detail.
export function RoadbookView({ id }: { id: string }) {
  const { t } = useTranslation();
  const theme = useTheme();
  const router = useRouter();
  const stages = useTripStore((s) => s.stages);
  const stageDiffs = useTripStore((s) => s.stageDiffs);
  const isLocked = useTripStore((s) => s.isLocked);
  const outOfZone = useTripStore((s) => s.outOfZone);
  const startDate = useTripStore((s) => s.startDate);
  const endDate = useTripStore((s) => s.endDate);

  const today = todayUtc();
  const state = tripStateFromDates(startDate, endDate, today);
  const hasDates = startDate !== null && endDate !== null;

  // One failure surface for every inline edit: map the normalized reason to a
  // localized alert (#1044). The runners already handle optimistic apply +
  // rollback; the authoritative state comes back over SSE (reconciled by core).
  const onFailure = useCallback(
    (reason: MutationFailure) =>
      Alert.alert(t('trip.edit.failedTitle'), t(`trip.edit.reason.${reason}`)),
    [t],
  );
  const mutations = useTripMutations(id, onFailure);

  // Per-row in-flight guard (#1044 review). A structural edit dispatches its
  // mutation immediately; without a guard a rapid double-tap on ＋étape / ＋repos
  // (or a double-submit of a distance edit) fires two calls for the same row
  // before the re-render disables the control, inserting twice. The ref gives a
  // synchronous check that survives React's async setState (two taps in the same
  // tick both read it), while `busyKeys` drives the disabled UI. Keyed by the
  // pre-edit stageKey, cleared when the mutation promise settles.
  const inFlight = useRef<Set<string>>(new Set());
  const [busyKeys, setBusyKeys] = useState<Set<string>>(new Set());
  const runGuarded = useCallback(
    (key: string, action: () => Promise<unknown> | void) => {
      if (inFlight.current.has(key)) return;
      inFlight.current.add(key);
      setBusyKeys(new Set(inFlight.current));
      void Promise.resolve(action()).finally(() => {
        inFlight.current.delete(key);
        setBusyKeys(new Set(inFlight.current));
      });
    },
    [],
  );

  function confirmDelete(index: number): void {
    Alert.alert(t('trip.deleteConfirmTitle'), t('trip.deleteConfirmMessage'), [
      { text: t('trip.cancel'), style: 'cancel' },
      {
        text: t('trip.delete'),
        style: 'destructive',
        onPress: () => void mutations.deleteStage(index),
      },
    ]);
  }

  const header = (
    <View
      style={{
        padding: theme.spacing.base,
        gap: theme.spacing.md,
      }}
    >
      {state ? (
        <Text
          style={{
            color: theme.colors[summaryColorKey(state)],
            fontFamily: theme.fonts.sansSemibold,
            fontSize: 15,
          }}
        >
          {t(`trip.states.${state}`)}
        </Text>
      ) : null}
      {isLocked ? (
        <RoadbookBanner variant="locked" message={t('trip.banners.locked')} />
      ) : null}
      {outOfZone ? (
        <RoadbookBanner variant="outOfZone" message={t('trip.banners.outOfZone')} />
      ) : null}
      {!hasDates ? (
        <RoadbookBanner variant="noDates" message={t('trip.banners.noDates')} />
      ) : null}
    </View>
  );

  return (
    <FlatList
      data={stages}
      extraData={stageDiffs}
      keyExtractor={(item) => stageKey(item)}
      ListHeaderComponent={header}
      ListEmptyComponent={
        <View style={{ height: 300 }}>
          <EmptyState title={t('trip.empty')} />
        </View>
      }
      renderItem={({ item, index }) => {
        const date = stageDateFor(startDate, item.dayNumber ?? index + 1);
        const key = stageKey(item);
        return (
          <StageCard
            stage={item}
            index={index}
            locked={isLocked}
            outOfZone={outOfZone}
            busy={busyKeys.has(key)}
            onDelete={confirmDelete}
            onAddStage={() => runGuarded(key, () => mutations.addStage(index))}
            onAddRestDay={() =>
              runGuarded(key, () => mutations.insertRestDay(index))
            }
            onEditDistance={(_, distance) =>
              runGuarded(key, () => mutations.updateStageDistance(index, distance))
            }
            onPress={(i) => router.push(`/trip/${id}/stage/${i}`)}
            date={date}
            isToday={state === 'ongoing' && isStageToday(date, today)}
            highlighted={stageDiffs.has(index)}
          />
        );
      }}
      style={{ backgroundColor: theme.colors.background }}
    />
  );
}
