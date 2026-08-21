import { useEffect, useState } from 'react';
import * as Location from 'expo-location';

export type LocationPermission = 'undetermined' | 'granted' | 'denied';

export interface ForegroundLocation {
  // 'undetermined' while the permission prompt is still pending, then 'granted'
  // or 'denied'. The in-ride screen renders an explicit empty state on 'denied'.
  permission: LocationPermission;
  // Latest fix, or null while waiting for the first one (or when denied).
  position: Location.LocationObjectCoords | null;
}

// Foreground-only GPS for the in-ride screen (#1149): request the when-in-use
// permission on mount, then subscribe to position updates for the lifetime of the
// mounted screen. NO background tracking — the subscription is removed on unmount,
// so location stops the moment the rider leaves the screen. The awaited
// watchPositionAsync can resolve after the component unmounts; `cancelled` guards
// that race and removes the subscription that arrived too late.
export function useForegroundLocation(): ForegroundLocation {
  const [permission, setPermission] = useState<LocationPermission>('undetermined');
  const [position, setPosition] = useState<Location.LocationObjectCoords | null>(null);

  useEffect(() => {
    let cancelled = false;
    let subscription: Location.LocationSubscription | null = null;

    void (async () => {
      try {
        const { status } = await Location.requestForegroundPermissionsAsync();
        if (cancelled) return;
        if (status !== 'granted') {
          setPermission('denied');
          return;
        }
        setPermission('granted');
        const sub = await Location.watchPositionAsync(
          { accuracy: Location.Accuracy.Balanced, distanceInterval: 10, timeInterval: 5000 },
          (loc) => {
            if (!cancelled) setPosition(loc.coords);
          },
        );
        if (cancelled) {
          sub.remove();
          return;
        }
        subscription = sub;
      } catch {
        // The permission/watch API itself can throw independently of granted/denied
        // (e.g. Location Services toggled off at the OS level) — surface it as
        // 'denied' so the screen falls back instead of hanging on "Recherche du
        // signal GPS…" forever. Same class of guard as fetchDeviceToken (push.ts).
        if (!cancelled) setPermission('denied');
      }
    })();

    return () => {
      cancelled = true;
      subscription?.remove();
    };
  }, []);

  return { permission, position };
}
