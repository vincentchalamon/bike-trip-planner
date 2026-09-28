import { useEffect } from 'react';
import { AppState } from 'react-native';
import type { MercureEvent } from '@btp/core/mercure';
import { enrichedPayloadToStageData, reconcileResync } from '@btp/core/reconciliation';
import { fetchTripDetail, type TripDetail } from '../api/trips';
import { subscribeToTrip, type TripSubscription } from '../api/mercure';
import { stageDataFromDetail, tripSettingsFromDetail } from '@btp/core';
import { useTripStore } from '../store/trip-store';
import { useDismissedAlerts } from '../store/dismissed-alerts';
import { useOfflineStore } from '../store/offline-store';
import { cacheTripDetail, readTripCache } from '../store/trip-cache';

// The store actions the orchestration drives.
export interface TripLiveStore {
  hydrate: ReturnType<typeof useTripStore.getState>['hydrate'];
  applyTripReady: ReturnType<typeof useTripStore.getState>['applyTripReady'];
  applyStageUpdate: ReturnType<typeof useTripStore.getState>['applyStageUpdate'];
  applyMercureEvent: ReturnType<typeof useTripStore.getState>['applyMercureEvent'];
  setStatus: ReturnType<typeof useTripStore.getState>['setStatus'];
  setComputing: ReturnType<typeof useTripStore.getState>['setComputing'];
}

// Load a trip into the store from /detail, then open the Mercure SSE
// subscription (header auth). SSE events are reconciled through the shared core
// reducers so mobile matches the web exactly (#1013/#1014). Returns the
// subscription (undefined when the trip could not be loaded) for the caller to
// close or reopen. `isCancelled` lets the caller drop late async work after
// unmount. Extracted from the hook so the async/error branches are
// unit-testable without a React renderer.
export async function runTripLive(
  id: string,
  store: TripLiveStore,
  isCancelled: () => boolean,
): Promise<TripSubscription | undefined> {
  store.setStatus({ loading: true, error: null });

  // Offline (#1147): hydrate straight from the persisted cache and skip the live
  // subscription — no network means no /detail and no Mercure token. The route
  // geometry is pulled separately by useTripRoute (also cache-aware).
  if (!useOfflineStore.getState().isOnline) {
    const cached = await readTripCache(id);
    if (isCancelled()) return undefined;
    if (cached) {
      store.hydrate(id, cached.detail);
      useDismissedAlerts.getState().reset();
      return deferredLive(id, store, isCancelled);
    }
  }

  let detail;
  try {
    detail = await fetchTripDetail(id);
  } catch {
    // Network failure: fall back to the cache before surfacing an error.
    const cached = await readTripCache(id);
    if (isCancelled()) return undefined;
    if (cached) {
      store.hydrate(id, cached.detail);
      useDismissedAlerts.getState().reset();
      return deferredLive(id, store, isCancelled);
    }
    // `error` carries an i18n key (translated at the display site), like the
    // 'notFound' code use-shared-trip stores — never a pre-translated string.
    store.setStatus({ loading: false, error: 'trip.loadError' });
    return undefined;
  }
  if (isCancelled()) return undefined;
  if (!detail) {
    store.setStatus({ loading: false, error: 'trip.notFound' });
    return undefined;
  }
  void cacheTripDetail(id, detail);
  store.hydrate(id, detail);
  // Alert dismissals are keyed on dayNumber:code, which collide across trips;
  // clear them when a new trip is loaded so a dismissal on one trip does not hide
  // the same code on another (the dismissed-alerts store is a global singleton).
  useDismissedAlerts.getState().reset();

  return openLive(id, store, isCancelled, false);
}

// Reconcile one SSE event into the store through the shared core reducers.
function dispatchEvent(store: TripLiveStore, event: MercureEvent): void {
  if (event.type === 'trip_ready') {
    store.applyTripReady(event.data.stages.map(enrichedPayloadToStageData));
    store.setComputing(false);
  } else if (event.type === 'stage_updated') {
    store.applyStageUpdate(
      event.data.stageId,
      event.data.position,
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
    // terminal and clears the computing badge. Do NOT disarm the diff
    // baseline here: the backend completion gate (TripCompletionGate /
    // AllEnrichmentsCompletedHandler) guarantees a trip_ready always
    // eventually follows once every pipeline computation has settled (done,
    // failed OR superseded) — including this one — so a single non-critical
    // failure (weather, ferries, border crossing, …) does not mean trip_ready
    // never arrives; disarming here would drop the highlight for that common
    // partial-failure case.
    //
    // That guarantee was in fact false until ADR-073: one message the trip had
    // moved past left its computation `pending` for good, the gate could never
    // close again, and this badge span forever. `superseded` is what closes it.
    if (!event.data.retryable) store.setComputing(false);
  } else {
    // Every other event — the enrichment stream (weather, POIs,
    // accommodations, every alert category, supply timeline, events) and
    // route_segment_recalculated (#1179) — is applied live via the shared
    // core reducer, so mobile no longer waits for the terminal trip_ready to
    // show them (matching the web). trip_ready / stage_updated keep their
    // dedicated actions above for the diff-baseline / endDate bookkeeping the
    // core reducer leaves to the store.
    store.applyMercureEvent(event);
  }
}

// Open the self-healing SSE subscription. Every reopen (token renewed, error
// recovered, foreground, network back) re-reads /detail, since the events
// published while the stream was down are never replayed; `stale` also resyncs
// on the first open, for a roadbook hydrated from the offline cache.
function openLive(
  id: string,
  store: TripLiveStore,
  isCancelled: () => boolean,
  stale: boolean,
): TripSubscription {
  let needsResync = stale;
  return subscribeToTrip(id, (event) => dispatchEvent(store, event), {
    onOpen: (reopened) => {
      if (reopened || needsResync) void resyncTrip(id, isCancelled);
      needsResync = false;
    },
  });
}

// A roadbook served from the offline cache opens no connection until it is asked
// to (foreground, network back): offline, there is neither a token nor a hub.
function deferredLive(
  id: string,
  store: TripLiveStore,
  isCancelled: () => boolean,
): TripSubscription {
  let live: TripSubscription | undefined;
  let closed = false;
  return {
    close: () => {
      closed = true;
      live?.close();
    },
    reconnect: () => {
      if (closed) return;
      if (live) live.reconnect();
      else live = openLive(id, store, isCancelled, true);
    },
  };
}

// Re-read /detail after a gap in the SSE stream. Failures are silent: the
// roadbook keeps what it has and the next reopen tries again.
export async function resyncTrip(id: string, isCancelled: () => boolean): Promise<void> {
  let detail;
  try {
    detail = await fetchTripDetail(id);
  } catch {
    return;
  }
  if (isCancelled() || !detail) return;
  void cacheTripDetail(id, detail);
  applyResync(id, detail);
}

// Fold a fresh /detail into the live store without the side effects of
// hydrate(): the undo history, the diff baseline and the stage geometry already
// fetched survive. Skipped while edits are queued: their optimistic state is not
// on the server yet and would be rolled back under the rider.
export function applyResync(id: string, detail: TripDetail): void {
  const state = useTripStore.getState();
  if (state.tripId !== id || state.pendingModifications.length > 0) return;
  const previous = new Map(state.stages.map((s) => [s.id, s]));
  const incoming = (detail.stages ?? []).map((s) => {
    const fresh = stageDataFromDetail(s);
    const prev = previous.get(fresh.id);
    return prev
      ? {
          ...fresh,
          geometry: prev.geometry,
          accommodationSearchRadiusKm: prev.accommodationSearchRadiusKm,
        }
      : fresh;
  });
  useTripStore.setState({
    stages: reconcileResync(state.stages, incoming),
    title: detail.title ?? null,
    isLocked: detail.isLocked ?? false,
    outOfZone: detail.outOfZone ?? false,
    ...tripSettingsFromDetail(detail),
    // A trip_ready missed during the gap would otherwise leave the badge on.
    computing: Object.values(detail.categoryStatus ?? {}).includes('running'),
    // The route may have moved during the gap: let useTripRoute fetch it again.
    geometryLoaded: false,
  });
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
  const applyMercureEvent = useTripStore((s) => s.applyMercureEvent);
  const setStatus = useTripStore((s) => s.setStatus);
  const setComputing = useTripStore((s) => s.setComputing);
  const reset = useTripStore((s) => s.reset);

  useEffect(() => {
    if (!enabled) return;
    let sub: TripSubscription | undefined;
    let cancelled = false;

    void runTripLive(
      id,
      {
        hydrate,
        applyTripReady,
        applyStageUpdate,
        applyMercureEvent,
        setStatus,
        setComputing,
      },
      () => cancelled,
    ).then((opened) => {
      if (cancelled) opened?.close();
      else sub = opened;
    });

    // Back in the foreground or back online: the stream may have died silently
    // (a backgrounded app's sockets get suspended), so reopen it, which resyncs.
    const appState = AppState.addEventListener('change', (state) => {
      if (state === 'active' && useOfflineStore.getState().isOnline) sub?.reconnect();
    });
    let wasOnline = useOfflineStore.getState().isOnline;
    const unsubscribeOnline = useOfflineStore.subscribe(({ isOnline }) => {
      if (isOnline && !wasOnline) sub?.reconnect();
      wasOnline = isOnline;
    });

    return () => {
      cancelled = true;
      appState.remove();
      unsubscribeOnline();
      sub?.close();
      reset();
    };
  }, [
    enabled,
    id,
    hydrate,
    applyTripReady,
    applyStageUpdate,
    applyMercureEvent,
    setStatus,
    setComputing,
    reset,
  ]);
}
