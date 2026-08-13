import { Alert, FlatList, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../ui';
import { StageCard } from './StageCard';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';
import { runDeleteStage } from '../../store/delete-stage';

// The roadbook tab: the stage list (StageCard rows) plus its empty state and the
// delete-stage confirm flow. #1037 refines the roadbook loading/empty states and
// #1039 wires each row's tap-through to the stage detail.
export function RoadbookView({ id }: { id: string }) {
  const { t } = useTranslation();
  const theme = useTheme();
  const stages = useTripStore((s) => s.stages);
  const isLocked = useTripStore((s) => s.isLocked);

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

  return (
    <FlatList
      data={stages}
      keyExtractor={(_, index) => String(index)}
      ListEmptyComponent={
        <View style={{ height: 300 }}>
          <EmptyState title={t('trip.empty')} />
        </View>
      }
      renderItem={({ item, index }) => (
        <StageCard stage={item} index={index} locked={isLocked} onDelete={confirmDelete} />
      )}
      style={{ backgroundColor: theme.colors.background }}
    />
  );
}
