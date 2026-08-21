import { useEffect } from 'react';
import NetInfo from '@react-native-community/netinfo';
import { useOfflineStore } from './offline-store';

// Bridges real device connectivity (NetInfo) into the offline store consumed by
// the mutation gate (gating.ts). Online means a usable internet path: connected
// AND not explicitly unreachable — `isInternetReachable` is null while NetInfo is
// still probing, which we treat as online rather than flapping the gate. Wired
// once at boot in app/_layout.tsx; the effect unsubscribes on unmount.
export function useConnectivity(): void {
  const setOnline = useOfflineStore((s) => s.setOnline);
  useEffect(() => {
    const unsubscribe = NetInfo.addEventListener((state) => {
      setOnline(state.isConnected === true && state.isInternetReachable !== false);
    });
    return unsubscribe;
  }, [setOnline]);
}
