import { Fragment, useCallback, useRef, useState } from 'react';
import { Alert, FlatList, Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../ui';
import { Bike } from '../ui/icons';
import { StageCard, stageKey } from './StageCard';
import { StageInsertRow } from './StageInsertRow';
import { RoadbookSummary } from './RoadbookSummary';
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
export function RoadbookView({
  id,
  onConfigureDates,
}: {
  id: string;
  // Opens the config sheet scrolled to the dates section (maquette 05a). Wired
  // to the "set your dates" banner so a rider fixes the missing dates in one tap.
  onConfigureDates?: () => void;
}) {
  const { t } = useTranslation();
  const theme = useTheme();
  const insets = useSafeAreaInsets();
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

  // Read-only when the backend locked the trip (started, 423) OR the dates put
  // it in progress / in the past (maquette 05e / 05f): every edit affordance
  // (insertion pills, delete, distance edit, FAB) is hidden or disabled.
  const readOnly = isLocked || state === 'ongoing' || state === 'past';

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
      {stages.length > 0 ? (
        <RoadbookSummary
          stages={stages}
          startDate={startDate}
          endDate={endDate}
        />
      ) : null}
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
        onConfigureDates ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('trip.banners.noDatesA11y')}
            onPress={onConfigureDates}
          >
            <RoadbookBanner variant="noDates" message={t('trip.banners.noDates')} />
          </Pressable>
        ) : (
          <RoadbookBanner variant="noDates" message={t('trip.banners.noDates')} />
        )
      ) : null}
    </View>
  );

  return (
    <View style={{ flex: 1 }}>
      <FlatList
        data={stages}
        extraData={stageDiffs}
        keyExtractor={(item) => stageKey(item)}
        ListHeaderComponent={header}
        contentContainerStyle={{ paddingBottom: theme.spacing['4xl'] }}
        ListEmptyComponent={
          <View style={{ height: 300 }}>
            <EmptyState title={t('trip.empty')} />
          </View>
        }
        renderItem={({ item, index }) => {
          const date = stageDateFor(startDate, item.dayNumber ?? index + 1);
          const key = stageKey(item);
          return (
            <Fragment>
              <StageCard
                stage={item}
                index={index}
                locked={readOnly}
                onDelete={confirmDelete}
                onPress={(i) => router.push(`/trip/${id}/stage/${i}`)}
                date={date}
                isToday={state === 'ongoing' && isStageToday(date, today)}
                highlighted={stageDiffs.has(index)}
              />
              {/* Insertion row BETWEEN stages only — never after the last one
                  (nothing to insert past the destination, like the web). Hidden
                  when the trip is read-only. Keyed on the preceding stage's key so
                  a rapid double-tap fires a single mutation. */}
              {!readOnly && index < stages.length - 1 ? (
                <StageInsertRow
                  afterIndex={index}
                  day={item.dayNumber ?? index + 1}
                  outOfZone={outOfZone}
                  busy={busyKeys.has(key)}
                  onAddStage={() =>
                    runGuarded(key, () => mutations.addStage(index))
                  }
                  onAddRestDay={() =>
                    runGuarded(key, () => mutations.insertRestDay(index))
                  }
                />
              ) : null}
            </Fragment>
          );
        }}
        style={{ backgroundColor: theme.colors.background }}
      />
      {/* In-ride FAB (Spike-UX): the in-ride screen is out of scope, so this is a
          deliberate placeholder — disabled, icon-only, dispatches nothing. Lifted
          above the system nav bar via the bottom safe-area inset. Lives in the
          roadbook view only, so it never overlays the map/profile tab (#7);
          hidden in the read-only (started / ongoing / past) view (#6). */}
      {stages.length > 0 && !readOnly ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('trip.rideCtaA11y')}
          accessibilityState={{ disabled: true }}
          disabled
          style={{
            position: 'absolute',
            right: theme.spacing.lg,
            bottom: insets.bottom + theme.spacing.lg,
            width: 52,
            height: 52,
            alignItems: 'center',
            justifyContent: 'center',
            backgroundColor: theme.colors.brandFill,
            borderRadius: theme.radius.full,
            ...theme.shadows.medium,
          }}
        >
          <Bike color={theme.colors.primaryForeground} size={22} />
        </Pressable>
      ) : null}
    </View>
  );
}
