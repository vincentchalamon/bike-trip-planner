import { create } from 'zustand';

// Lightweight temporal (undo/redo) companion store, mirrored from the web
// (pwa/src/store/temporal-middleware.ts). Web and mobile keep separate copies of
// this generic undo stack because each binds to its own zustand host store
// (immer on web, plain zustand here); the shared roadbook *domain* logic (schema
// + SSE reconciliation) lives in @btp/core, this UI history stack does not.
//
// Snapshots are plain JSON-serialisable slices pushed *before* each tracked
// mutation via `_push()`; `_pop()` drops the last snapshot on optimistic
// rollback so a failed mutation leaves no phantom undo entry.

const MAX_HISTORY = 50;

export interface TemporalState {
  canUndo: boolean;
  canRedo: boolean;
  undo: () => void;
  redo: () => void;
  /** Clears all history (past and future). Call when loading a new trip. */
  clear: () => void;
  /** @internal Push a new snapshot onto the past stack (clears redo stack). */
  _push: (snapshot: unknown) => void;
  /** @internal Pop the most-recent past entry. Call on optimistic-rollback. */
  _pop: () => void;
}

/**
 * Creates the companion temporal store bound to a host zustand store.
 *
 * @param getState - Returns the current tracked-slice value from the host store.
 * @param setState - Applies a tracked-slice snapshot back to the host store.
 */
export function createTemporalStore(
  getState: () => unknown,
  setState: (snapshot: unknown) => void,
) {
  let past: unknown[] = [];
  let future: unknown[] = [];

  return create<TemporalState>()((set) => ({
    canUndo: false,
    canRedo: false,

    clear: () => {
      past = [];
      future = [];
      set({ canUndo: false, canRedo: false });
    },

    _push: (snapshot) => {
      if (past.length >= MAX_HISTORY) {
        past = past.slice(past.length - MAX_HISTORY + 1);
      }
      past = [...past, snapshot];
      future = [];
      set({ canUndo: true, canRedo: false });
    },

    _pop: () => {
      if (past.length === 0) return;
      past = past.slice(0, -1);
      set({ canUndo: past.length > 0 });
    },

    undo: () => {
      if (past.length === 0) return;
      const current = getState();
      const previous = past[past.length - 1]!;
      past = past.slice(0, -1);
      future = [current, ...future];
      setState(previous);
      set({ canUndo: past.length > 0, canRedo: true });
    },

    redo: () => {
      if (future.length === 0) return;
      const current = getState();
      const next = future[0]!;
      future = future.slice(1);
      past = [...past, current];
      setState(next);
      set({ canUndo: true, canRedo: future.length > 0 });
    },
  }));
}
