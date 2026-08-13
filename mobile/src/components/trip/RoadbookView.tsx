import { Alert, FlatList, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../ui';
import { StageCard } from './StageCard';
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
import { runDeleteStage } from '../../store/delete-stage';

// The roadbook tab: a lifecycle summary header (upcoming / ongoing / past),
// the lock / out-of-zone / no-dates banners, then the stage list (StageCard
// rows) with per-stage dates and an "Aujourd'hui" pastille. #1039 wires each
// row's tap-through to the stage detail.
export function RoadbookView({ id }: { id: string }) {
  const { t } = useTranslation();
  const theme = useTheme();
  const router = useRouter();
  const stages = useTripStore((s) => s.stages);
  const isLocked = useTripStore((s) => s.isLocked);
  const outOfZone = useTripStore((s) => s.outOfZone);
  const startDate = useTripStore((s) => s.startDate);
  const endDate = useTripStore((s) => s.endDate);

  const today = todayUtc();
  const state = tripStateFromDates(startDate, endDate, today);
  const hasDates = startDate !== null && endDate !== null;

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
      keyExtractor={(_, index) => String(index)}
      ListHeaderComponent={header}
      ListEmptyComponent={
        <View style={{ height: 300 }}>
          <EmptyState title={t('trip.empty')} />
        </View>
      }
      renderItem={({ item, index }) => {
        const date = stageDateFor(startDate, item.dayNumber ?? index + 1);
        return (
          <StageCard
            stage={item}
            index={index}
            locked={isLocked}
            onDelete={confirmDelete}
            onPress={(i) => router.push(`/trip/${id}/stage/${i}`)}
            date={date}
            isToday={state === 'ongoing' && isStageToday(date, today)}
          />
        );
      }}
      style={{ backgroundColor: theme.colors.background }}
    />
  );
}
