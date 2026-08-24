import { useEffect } from 'react';
import type { StageData } from '@btp/core';
import {
  fetchSharedTrip,
  fetchSharedTripRoute,
  type SharedTripDetail,
  type TripDetail,
} from '../api/trips';
import { stageDataFromDetail, useTripStore } from '../store/trip-store';

// The store actions the shared consultation drives. A read-only subset of the
// live orchestration (no SSE reconciliation): the shared view never mutates.
export interface SharedTripStore {
  reset: ReturnType<typeof useTripStore.getState>['reset'];
  setStages: ReturnType<typeof useTripStore.getState>['setStages'];
  setConfig: ReturnType<typeof useTripStore.getState>['setConfig'];
  setTitle: ReturnType<typeof useTripStore.getState>['setTitle'];
  setIsLocked: ReturnType<typeof useTripStore.getState>['setIsLocked'];
  setOutOfZone: ReturnType<typeof useTripStore.getState>['setOutOfZone'];
  setStatus: ReturnType<typeof useTripStore.getState>['setStatus'];
  applyRoute: ReturnType<typeof useTripStore.getState>['applyRoute'];
}

// The shared stage DTO is structurally the trip /detail stage; reuse the store's
// mapper. (The DTO carries no `id`/live fields the mapper reads, only geometry &
// summary, so the cast is safe — same field names as TripDetail.stages.)
type SharedStage = NonNullable<SharedTripDetail['stages']>[number];

/**
 * Hydrate the shared store from `/s/<code>` then merge the on-demand geometry
 * from `/s/<code>/route`, read-only. Extracted from the hook so the async / error
 * branches are unit-testable without a React renderer.
 *
 * Unlike {@link runTripLive}, the store's `tripId` is left null on purpose: the
 * anonymous client must never issue an auth-gated `/trips/{id}/route` call, and
 * `useTripRoute` (fired eagerly by the map tab) is gated on `tripId`, so a null
 * id keeps the map off the private endpoint — the geometry it needs is applied
 * here from the public `/route` instead.
 */
export async function runSharedTrip(
  code: string,
  store: SharedTripStore,
  isCancelled: () => boolean,
): Promise<void> {
  store.reset();
  store.setStatus({ loading: true, error: null });

  const detail = await fetchSharedTrip(code);
  if (isCancelled()) return;
  if (!detail) {
    store.setStatus({ loading: false, error: 'notFound' });
    return;
  }

  const stages: StageData[] = (detail.stages ?? []).map((s) =>
    stageDataFromDetail(s as SharedStage as NonNullable<TripDetail['stages']>[number]),
  );
  store.setStages(stages);
  store.setTitle(detail.title ?? '');
  store.setIsLocked(detail.isLocked ?? false);
  store.setOutOfZone(detail.outOfZone ?? false);
  store.setConfig({
    startDate: detail.startDate ?? null,
    endDate: detail.endDate ?? null,
    fatigueFactor: detail.fatigueFactor ?? 0.9,
    elevationPenalty: detail.elevationPenalty ?? 50,
    maxDistancePerDay: detail.maxDistancePerDay ?? 80,
    averageSpeed: detail.averageSpeed ?? 15,
  });
  store.setStatus({ loading: false, error: null });

  // Geometry (ADR-057) is split off the summary; pull it via the public code and
  // merge it into the stages so the map/profile render a line. A miss just leaves
  // an empty map — never fatal.
  const route = await fetchSharedTripRoute(code);
  if (isCancelled() || !route) return;
  store.applyRoute(route);
}

// Loads a shared trip into the store for a read-only consultation. Resets the
// store on unmount so leaving the shared page never leaks stages into a fresh
// planner session (mirrors the web shared page's clearTrip cleanup).
export function useSharedTrip(code: string): void {
  const reset = useTripStore((s) => s.reset);
  const setStages = useTripStore((s) => s.setStages);
  const setConfig = useTripStore((s) => s.setConfig);
  const setTitle = useTripStore((s) => s.setTitle);
  const setIsLocked = useTripStore((s) => s.setIsLocked);
  const setOutOfZone = useTripStore((s) => s.setOutOfZone);
  const setStatus = useTripStore((s) => s.setStatus);
  const applyRoute = useTripStore((s) => s.applyRoute);

  useEffect(() => {
    let cancelled = false;
    void runSharedTrip(
      code,
      { reset, setStages, setConfig, setTitle, setIsLocked, setOutOfZone, setStatus, applyRoute },
      () => cancelled,
    );
    return () => {
      cancelled = true;
      reset();
    };
  }, [
    code,
    reset,
    setStages,
    setConfig,
    setTitle,
    setIsLocked,
    setOutOfZone,
    setStatus,
    applyRoute,
  ]);
}
