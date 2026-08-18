import { useEffect } from 'react';
import { enrichedPayloadToStageData } from '@btp/core/reconciliation';
import { fetchTripDetail } from '../api/trips';
import {
  fetchMercureToken,
  subscribeToTrip,
  type TripSubscription,
} from '../api/mercure';
import { useTripStore } from '../store/trip-store';
import { useDismissedAlerts } from '../store/dismissed-alerts';

// The store actions the orchestration drives.
export interface TripLiveStore {
  hydrate: ReturnType<typeof useTripStore.getState>['hydrate'];
  applyTripReady: ReturnType<typeof useTripStore.getState>['applyTripReady'];
  applyStageUpdate: ReturnType<typeof useTripStore.getState>['applyStageUpdate'];
  setStatus: ReturnType<typeof useTripStore.getState>['setStatus'];
  setComputing: ReturnType<typeof useTripStore.getState>['setComputing'];
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
  // Alert dismissals are keyed on dayNumber:code, which collide across trips;
  // clear them when a new trip is loaded so a dismissal on one trip does not hide
  // the same code on another (the dismissed-alerts store is a global singleton).
  useDismissedAlerts.getState().reset();

  try {
    const token = await fetchMercureToken(id);
    if (isCancelled()) return undefined;
    return subscribeToTrip(id, token, (event) => {
      if (event.type === 'trip_ready') {
        store.applyTripReady(event.data.stages.map(enrichedPayloadToStageData));
        store.setComputing(false);
      } else if (event.type === 'stage_updated') {
        store.applyStageUpdate(
          event.data.stageIndex,
          enrichedPayloadToStageData(event.data.stage),
        );
      } else if (event.type === 'computation_step_completed') {
        // Progress tick: a recompute is streaming. Show the SSE badge until the
        // terminal trip_ready / trip_complete arrives.
        store.setComputing(true);
      } else if (event.type === 'trip_complete') {
        store.setComputing(false);
      } else if (event.type === 'computation_error') {
        // A retryable error means the computation is still running (the core
        // reducer leaves the state untouched); only a non-retryable error is
        // terminal and clears the computing badge.
        if (!event.data.retryable) store.setComputing(false);
      }
    });
  } catch {
    // Live updates unavailable (e.g. token fetch failed); the hydrated roadbook
    // still renders, just without SSE reconciliation.
    return undefined;
  }
}

// Loads a trip into the shared store and keeps it live via Mercure SSE.
// `options.enabled` (default true) gates the whole orchestration: when false,
// nothing is subscribed and the store is never reset on unmount — the caller
// already owns the live store (e.g. the stage detail reached by tap-through,
// where a reset would blank the roadbook mounted underneath).
export function useTripLive(id: string, options?: { enabled?: boolean }): void {
  const enabled = options?.enabled ?? true;
  const hydrate = useTripStore((s) => s.hydrate);
  const applyTripReady = useTripStore((s) => s.applyTripReady);
  const applyStageUpdate = useTripStore((s) => s.applyStageUpdate);
  const setStatus = useTripStore((s) => s.setStatus);
  const setComputing = useTripStore((s) => s.setComputing);
  const reset = useTripStore((s) => s.reset);

  useEffect(() => {
    if (!enabled) return;
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
  }, [enabled, id, hydrate, applyTripReady, applyStageUpdate, setStatus, setComputing, reset]);
}
