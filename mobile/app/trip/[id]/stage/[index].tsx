import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ErrorState, Screen } from '../../../../src/components/ui';
import { StageDetailView } from '../../../../src/components/trip';
import { ownsTripLive, parseStageIndex } from '../../../../src/components/trip/stage-detail';
import { useTripLive } from '../../../../src/hooks/use-trip-live';
import { useTripStore } from '../../../../src/store/trip-store';

// Full-screen stage detail (#1039). Reuses the store's live orchestration
// (useTripLive) so a deep link that mounts this screen directly still hydrates
// the store + opens the SSE subscription. When reached by tapping a StageCard,
// the roadbook already owns the live store for this trip, so we disable the
// orchestration (enabled: owns) to avoid re-hydrating or resetting on unmount —
// that would blank the roadbook underneath on back-navigation. Ownership is
// decided once, at mount.
export default function StageDetailScreen() {
  const { id, index } = useLocalSearchParams<{ id: string; index: string }>();
  const error = useTripStore((s) => s.error);

  // Own the live store only when it isn't already live for this trip (deep-link
  // entry). Captured once so a later tripId change doesn't tear it down.
  const [owns] = useState(() => ownsTripLive(useTripStore.getState().tripId, id));

  useTripLive(id, { enabled: owns });

  if (error) {
    return (
      <Screen padded={false}>
        <ErrorState title={error} />
      </Screen>
    );
  }

  return (
    <Screen padded={false} edges={['left', 'right']}>
      <StageDetailView initialIndex={parseStageIndex(index)} />
    </Screen>
  );
}
