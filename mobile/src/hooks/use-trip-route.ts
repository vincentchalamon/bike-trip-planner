import { useEffect } from 'react';
import { fetchTripRoute } from '../api/trips';
import { useTripStore } from '../store/trip-store';

// Fetch the trip's route geometry once (ADR-057) and merge it into the store's
// stages. The summary (/detail) carries no geometry, so the map, the stage
// mini-map / elevation profile AND the shared infographic depend on this.
// Idempotent via the store's `geometryLoaded` flag, and re-runs after a fresh
// hydrate (which clears it).
//
// `enabled` gates the fetch so callers that don't always need geometry (e.g. the
// share sheet, only when it opens) don't eagerly pull /route on every roadbook
// open — keeping ADR-057's "fetched only when actually needed" intent.
export function useTripRoute(options?: { enabled?: boolean }): void {
  const enabled = options?.enabled ?? true;
  const tripId = useTripStore((s) => s.tripId);
  const geometryLoaded = useTripStore((s) => s.geometryLoaded);
  const applyRoute = useTripStore((s) => s.applyRoute);

  useEffect(() => {
    if (!enabled || !tripId || geometryLoaded) return;
    let cancelled = false;
    void fetchTripRoute(tripId)
      .then((route) => {
        if (!cancelled && route) applyRoute(route);
      })
      .catch((error: unknown) => {
        // Graceful degradation (the map/profile just stay empty), but don't
        // swallow it silently — it's the only signal the route never loaded.
        console.warn('Failed to load trip route geometry', error);
      });
    return () => {
      cancelled = true;
    };
  }, [enabled, tripId, geometryLoaded, applyRoute]);
}
