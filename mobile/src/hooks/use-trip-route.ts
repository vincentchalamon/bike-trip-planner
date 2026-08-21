import { useEffect } from 'react';
import { fetchTripRoute, type TripRoute } from '../api/trips';
import { useTripStore } from '../store/trip-store';
import { useOfflineStore } from '../store/offline-store';
import { cacheTripRoute, readTripCache } from '../store/trip-cache';

// Load the trip's route geometry (ADR-057), offline-aware (#1147): offline we
// serve the cached tracé, and a network failure falls back to it too; a
// successful online load refreshes the cache. Never throws — a miss just leaves
// the map/profile empty (returns null). Extracted so the branches are testable.
export async function runLoadTripRoute(id: string): Promise<TripRoute | null> {
  if (!useOfflineStore.getState().isOnline) {
    const cached = await readTripCache(id);
    if (cached?.route) return cached.route;
  }
  try {
    const route = await fetchTripRoute(id);
    if (route) void cacheTripRoute(id, route);
    return route;
  } catch (error: unknown) {
    const cached = await readTripCache(id);
    if (cached?.route) return cached.route;
    // Genuine failure: online (or nothing cached) and the fetch threw. Surface a
    // diagnostic so a real backend/network error is not swallowed silently.
    console.warn('Failed to load trip route geometry', error);
    return null;
  }
}

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
    void runLoadTripRoute(tripId).then((route) => {
      if (!cancelled && route) applyRoute(route);
    });
    return () => {
      cancelled = true;
    };
  }, [enabled, tripId, geometryLoaded, applyRoute]);
}
