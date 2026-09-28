import { evaluateGate } from '../store/gating';
import { useOfflineStore } from '../store/offline-store';
import { useTripStore } from '../store/trip-store';

// The mutation gate (evaluateGate) read live from the stores, so a screen
// disables exactly what the mutation runners would refuse. Returns the blocking
// reason, or null when an edit of that kind may proceed. `requiresRouting` is
// true for the edits that reroute through Valhalla, the only ones an
// out-of-zone trip blocks.
export function useEditGate(requiresRouting: boolean): ReturnType<typeof evaluateGate> {
  const isLocked = useTripStore((s) => s.isLocked);
  const outOfZone = useTripStore((s) => s.outOfZone);
  const isOnline = useOfflineStore((s) => s.isOnline);
  const apiReachable = useOfflineStore((s) => s.apiReachable);
  return evaluateGate({ isLocked, outOfZone, isOnline, apiReachable }, requiresRouting);
}
