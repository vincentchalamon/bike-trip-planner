import { useEffect } from 'react';
import type { StageData } from '@btp/core';
import { fetchStageDetail } from '../api/trips';
import { useTripStore } from '../store/trip-store';

// Fetch one stage's full detail (GET /stages/{index}/detail) and merge its
// geometry into the store, for the stage screen's mini-map + elevation profile.
// Unlike the map tab (which needs the whole route), the detail screen pulls only
// its own ~300 points (ADR-057). Gated on that stage already having geometry, so
// it fetches once per stage and re-fetches when the user navigates to another.
export function useStageDetail(index: number): void {
  const tripId = useTripStore((s) => s.tripId);
  const hasGeometry = useTripStore(
    (s) => (s.stages[index]?.geometry?.length ?? 0) > 0,
  );
  const applyStageDetail = useTripStore((s) => s.applyStageDetail);

  useEffect(() => {
    if (!tripId || hasGeometry) return;
    let cancelled = false;
    void fetchStageDetail(tripId, index)
      .then((detail) => {
        if (!cancelled && detail) {
          applyStageDetail(index, (detail.geometry ?? []) as StageData['geometry']);
        }
      })
      .catch((error: unknown) => {
        // Graceful degradation (mini-map/profile stay empty) but not silent.
        console.warn('Failed to load stage detail geometry', error);
      });
    return () => {
      cancelled = true;
    };
  }, [tripId, index, hasGeometry, applyStageDetail]);
}
