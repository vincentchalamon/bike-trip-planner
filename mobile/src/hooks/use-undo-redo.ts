import { useTripStore, useTripTemporalStore } from '../store/trip-store';

/**
 * Undo/redo state for the roadbook menu (#1178, parity with the web
 * `useUndoRedo`). Exposes `canUndo`/`canRedo` plus guarded `undo`/`redo`.
 *
 * Undo/redo only replay already-in-memory roadbook state (no API call), so —
 * unlike the write mutations gated by #1166 — they stay available offline / when
 * the API is down (local-first, web parity): losing them on a connection blip
 * would be the wrong call for a bikepacking app. A started trip is fully
 * read-only (423 on every edit), so `isLocked` still disables them; out-of-zone
 * does not (they never reroute).
 */
export function useUndoRedo() {
  const canUndoRaw = useTripTemporalStore((s) => s.canUndo);
  const canRedoRaw = useTripTemporalStore((s) => s.canRedo);
  const undoRaw = useTripTemporalStore((s) => s.undo);
  const redoRaw = useTripTemporalStore((s) => s.redo);
  const isLocked = useTripStore((s) => s.isLocked);

  return {
    canUndo: canUndoRaw && !isLocked,
    canRedo: canRedoRaw && !isLocked,
    undo: () => {
      if (!isLocked) undoRaw();
    },
    redo: () => {
      if (!isLocked) redoRaw();
    },
  };
}
