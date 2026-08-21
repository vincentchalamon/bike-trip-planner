import { Stack, useLocalSearchParams } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Screen } from '../../../src/components/ui';
import { InRidePanel, InRideView } from '../../../src/components/trip';

// In-ride route (#1149): reached from the roadbook "En selle" FAB. Thin wrapper —
// the body (foreground GPS, offline badge, help bubble, #1150 POI slot) lives in
// InRideView so it renders in tests without a mounted navigator.
export default function InRide() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useTranslation();
  return (
    <Screen padded={false} edges={['left', 'right']}>
      <Stack.Screen options={{ headerShown: true, headerTitle: t('trip.inRide.title') }} />
      <InRideView
        tripId={id}
        poiPanel={(location) => <InRidePanel tripId={id} location={location} />}
      />
    </Screen>
  );
}
