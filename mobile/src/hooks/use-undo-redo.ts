import { useTripStore, useTripTemporalStore } from '../store/trip-store';
import { useOfflineStore } from '../store/offline-store';
import { evaluateGate } from '../store/gating';

/**
 * Undo/redo state for the roadbook menu (#1178, parity with the web
 * `useUndoRedo`). Exposes `canUndo`/`canRedo` plus guarded `undo`/`redo`.
 *
 * Undo/redo restore local roadbook state, but the transversal gate (#1166)
 * treats them as writes: a started trip or a degraded connection (offline /
 * API-down) disables and refuses them like every other mutation. Out-of-zone
 * does not block them (they never reroute).
 */
export function useUndoRedo() {
  const canUndoRaw = useTripTemporalStore((s) => s.canUndo);
  const canRedoRaw = useTripTemporalStore((s) => s.canRedo);
  const undoRaw = useTripTemporalStore((s) => s.undo);
  const redoRaw = useTripTemporalStore((s) => s.redo);
  const isLocked = useTripStore((s) => s.isLocked);
  const outOfZone = useTripStore((s) => s.outOfZone);
  const isOnline = useOfflineStore((s) => s.isOnline);
  const apiReachable = useOfflineStore((s) => s.apiReachable);

  const blocked =
    evaluateGate({ isLocked, outOfZone, isOnline, apiReachable }, false) !== null;

  return {
    canUndo: canUndoRaw && !blocked,
    canRedo: canRedoRaw && !blocked,
    undo: () => {
      if (!blocked) undoRaw();
    },
    redo: () => {
      if (!blocked) redoRaw();
    },
  };
}
