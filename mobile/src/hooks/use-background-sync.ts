import { useEffect } from 'react';
import { AppState } from 'react-native';
import { fetchTripDetail, fetchTripRoute } from '../api/trips';
import { useOfflineStore } from '../store/offline-store';
import { syncCachedTrips } from '../store/trip-cache';

// Real dependencies for the background re-sync. Built once: the fetchers are
// stable module functions and `isOnline` reads the store lazily at call time.
const deps = {
  isOnline: () => useOfflineStore.getState().isOnline,
  fetchDetail: fetchTripDetail,
  fetchRoute: fetchTripRoute,
};

// Keep the offline cache of upcoming/ongoing trips fresh (#1147): silently
// re-fetch every cached trip whenever the device comes back online or the app
// returns to the foreground. No manual "offline mode" — this just tops up the
// cache in the background. Wired once at boot in app/_layout.tsx.
export function useBackgroundTripSync(): void {
  const isOnline = useOfflineStore((s) => s.isOnline);

  // Coming (back) online: refresh the cache. Also runs on mount when already
  // online, seeding a fresh sync at launch.
  useEffect(() => {
    if (isOnline) void syncCachedTrips(deps);
  }, [isOnline]);

  // App returns to the foreground: the cache may be stale after time in the
  // background. syncCachedTrips itself bails out when still offline.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') void syncCachedTrips(deps);
    });
    return () => sub.remove();
  }, []);
}
