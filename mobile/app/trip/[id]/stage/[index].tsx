import { useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { ErrorState, Screen } from '../../../../src/components/ui';
import { StageDetailView } from '../../../../src/components/trip';
import { parseStageIndex } from '../../../../src/components/trip/stage-detail';
import { runTripLive } from '../../../../src/hooks/use-trip-live';
import type { TripSubscription } from '../../../../src/api/mercure';
import { useTripStore } from '../../../../src/store/trip-store';

// Full-screen stage detail (#1039). Reuses the store's live orchestration
// (runTripLive) so a deep link that mounts this screen directly still hydrates
// the store + opens the SSE subscription. When reached by tapping a StageCard,
// the roadbook already owns the live store for this trip, so we do NOT
// re-hydrate or reset on unmount — that would blank the roadbook underneath on
// back-navigation. Ownership is decided once, at mount.
export default function StageDetailScreen() {
  const { id, index } = useLocalSearchParams<{ id: string; index: string }>();
  const hydrate = useTripStore((s) => s.hydrate);
  const applyTripReady = useTripStore((s) => s.applyTripReady);
  const applyStageUpdate = useTripStore((s) => s.applyStageUpdate);
  const setStatus = useTripStore((s) => s.setStatus);
  const setComputing = useTripStore((s) => s.setComputing);
  const reset = useTripStore((s) => s.reset);
  const error = useTripStore((s) => s.error);

  // Own the live store only when it isn't already live for this trip (deep-link
  // entry). Captured once so a later tripId change doesn't tear it down.
  const [owns] = useState(() => useTripStore.getState().tripId !== id);

  useEffect(() => {
    if (!owns) return;
    let sub: TripSubscription | undefined;
    let cancelled = false;
    void runTripLive(
      id,
      { hydrate, applyTripReady, applyStageUpdate, setStatus, setComputing },
      () => cancelled,
    ).then((opened) => {
      if (cancelled) opened?.close();
      else sub = opened;
    });
    return () => {
      cancelled = true;
      sub?.close();
      reset();
    };
  }, [owns, id, hydrate, applyTripReady, applyStageUpdate, setStatus, setComputing, reset]);

  if (error) {
    return (
      <Screen padded={false}>
        <ErrorState title={error} />
      </Screen>
    );
  }

  return (
    <Screen padded={false} edges={['left', 'right']}>
      <StageDetailView id={id} initialIndex={parseStageIndex(index)} />
    </Screen>
  );
}
