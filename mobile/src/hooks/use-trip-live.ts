import { useEffect } from 'react';
import { enrichedPayloadToStageData } from '@btp/core/reconciliation';
import { fetchTripDetail } from '../api/trips';
import {
  fetchMercureToken,
  subscribeToTrip,
  type TripSubscription,
} from '../api/mercure';
import { useTripStore } from '../store/trip-store';

// The store actions the orchestration drives.
export interface TripLiveStore {
  hydrate: ReturnType<typeof useTripStore.getState>['hydrate'];
  applyTripReady: ReturnType<typeof useTripStore.getState>['applyTripReady'];
  applyStageUpdate: ReturnType<typeof useTripStore.getState>['applyStageUpdate'];
  setStatus: ReturnType<typeof useTripStore.getState>['setStatus'];
}

// Load a trip into the store from /detail, then open the Mercure SSE
// subscription (header auth). SSE events are reconciled through the shared core
// reducers so mobile matches the web exactly (#1013/#1014). Returns the
// subscription (or undefined when none was opened) for the caller to close.
// `isCancelled` lets the caller drop late async work after unmount. Extracted
// from the hook so the async/error branches are unit-testable without a React
// renderer.
export async function runTripLive(
  id: string,
  store: TripLiveStore,
  isCancelled: () => boolean,
): Promise<TripSubscription | undefined> {
  store.setStatus({ loading: true, error: null });

  let detail;
  try {
    detail = await fetchTripDetail(id);
  } catch {
    if (!isCancelled()) {
      store.setStatus({ loading: false, error: 'Impossible de charger le roadbook.' });
    }
    return undefined;
  }
  if (isCancelled()) return undefined;
  if (!detail) {
    store.setStatus({ loading: false, error: 'Voyage introuvable.' });
    return undefined;
  }
  store.hydrate(id, detail);

  try {
    const token = await fetchMercureToken(id);
    if (isCancelled()) return undefined;
    return subscribeToTrip(id, token, (event) => {
      if (event.type === 'trip_ready') {
        store.applyTripReady(event.data.stages.map(enrichedPayloadToStageData));
      } else if (event.type === 'stage_updated') {
        store.applyStageUpdate(
          event.data.stageIndex,
          enrichedPayloadToStageData(event.data.stage),
        );
      }
    });
  } catch {
    // Live updates unavailable (e.g. token fetch failed); the hydrated roadbook
    // still renders, just without SSE reconciliation.
    return undefined;
  }
}

// Loads a trip into the shared store and keeps it live via Mercure SSE.
export function useTripLive(id: string): void {
  const hydrate = useTripStore((s) => s.hydrate);
  const applyTripReady = useTripStore((s) => s.applyTripReady);
  const applyStageUpdate = useTripStore((s) => s.applyStageUpdate);
  const setStatus = useTripStore((s) => s.setStatus);
  const reset = useTripStore((s) => s.reset);

  useEffect(() => {
    let sub: TripSubscription | undefined;
    let cancelled = false;

    void runTripLive(
      id,
      { hydrate, applyTripReady, applyStageUpdate, setStatus },
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
  }, [id, hydrate, applyTripReady, applyStageUpdate, setStatus, reset]);
}
