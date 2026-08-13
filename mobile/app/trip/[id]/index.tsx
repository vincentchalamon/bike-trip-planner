import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import {
  ErrorState,
  LoadingState,
  Screen,
  SegmentedControl,
  type Segment,
} from '../../../src/components/ui';
import { RoadbookView, SseStatusIndicator, TripMapView } from '../../../src/components/trip';
import { useTheme } from '../../../src/theme';
import { useTripLive } from '../../../src/hooks/use-trip-live';
import { useTripStore } from '../../../src/store/trip-store';

type TripView = 'roadbook' | 'map';

export default function TripRoadbook() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useTranslation();
  const theme = useTheme();
  const [view, setView] = useState<TripView>('roadbook');

  // Hydrate the shared store from /detail and keep it live via SSE. The child
  // views render straight from the store, so a stage_updated event reconciled by
  // the core reducers updates them in place (no ad-hoc local state).
  useTripLive(id);

  const title = useTripStore((s) => s.title);
  const computing = useTripStore((s) => s.computing);
  const loading = useTripStore((s) => s.loading);
  const error = useTripStore((s) => s.error);

  const segments: readonly Segment<TripView>[] = [
    { value: 'roadbook', label: t('trip.segmentRoadbook') },
    { value: 'map', label: t('trip.segmentMap') },
  ];

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
        <ErrorState title={t('common.error')} description={error} />
      </Screen>
    );
  }

  return (
    <Screen padded={false}>
      <View style={{ padding: theme.spacing.base, gap: theme.spacing.md }}>
        <View
          style={{
            flexDirection: 'row',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: theme.spacing.md,
          }}
        >
          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.serif,
              fontSize: 24,
              flex: 1,
            }}
          >
            {title ?? t('trip.title')}
          </Text>
          <SseStatusIndicator computing={computing} />
        </View>
        <SegmentedControl segments={segments} value={view} onChange={setView} />
      </View>

      {view === 'map' ? <TripMapView /> : <RoadbookView id={id} />}
    </Screen>
  );
}
