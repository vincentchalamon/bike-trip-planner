import { useEffect } from 'react';
import { fetchTripRoute } from '../api/trips';
import { useTripStore } from '../store/trip-store';

// Fetch the trip's route geometry once (ADR-057) and merge it into the store's
// stages. The summary (/detail) carries no geometry, so the map and the stage
// mini-map / elevation profile depend on this. Idempotent via the store's
// `geometryLoaded` flag, and re-runs after a fresh hydrate (which clears it).
export function useTripRoute(): void {
  const tripId = useTripStore((s) => s.tripId);
  const geometryLoaded = useTripStore((s) => s.geometryLoaded);
  const applyRoute = useTripStore((s) => s.applyRoute);

  useEffect(() => {
    if (!tripId || geometryLoaded) return;
    let cancelled = false;
    void fetchTripRoute(tripId)
      .then((route) => {
        if (!cancelled && route) applyRoute(route);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [tripId, geometryLoaded, applyRoute]);
}
