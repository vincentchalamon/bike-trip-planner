import { create } from 'zustand';

// Lightweight temporal (undo/redo) companion store, mirrored from the web
// (pwa/src/store/temporal-middleware.ts). Web and mobile keep separate copies of
// this generic undo stack because each binds to its own zustand host store
// (immer on web, plain zustand here); the shared roadbook *domain* logic (schema
// + SSE reconciliation) lives in @btp/core, this UI history stack does not.
//
// Snapshots are plain JSON-serialisable slices pushed *before* each tracked
// mutation via `_push()`, which returns a token naming the entry. An optimistic
// edit the server refuses hands that token to `_discard()`, which removes that
// entry and no other: requests settle in any order, so the entry to drop is not
// necessarily the most recent one. Every snapshot taken after the refused edit
// captured its optimistic value, so `_discard()` also runs them through
// `revert` — otherwise undoing a later edit would bring the refused value back.

const MAX_HISTORY = 50;

/** Names one history entry, so a refused edit can withdraw exactly its own. */
export type UndoToken = symbol;

interface Entry {
  token: UndoToken;
  snapshot: unknown;
}

export interface TemporalState {
  canUndo: boolean;
  canRedo: boolean;
  undo: () => void;
  redo: () => void;
  /** Clears all history (past and future). Call when loading a new trip. */
  clear: () => void;
  /** @internal Push a new snapshot onto the past stack (clears redo stack). */
  _push: (snapshot: unknown) => UndoToken;
  /**
   * @internal Withdraw the entry `token` names, wherever it sits in the past
   * stack, and pass every later snapshot (redo stack included) through
   * `revert`. Call on optimistic rollback. No-op when the entry is gone
   * (undone, evicted, or the history was cleared).
   */
  _discard: (token: UndoToken, revert?: (snapshot: unknown) => unknown) => void;
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
  let past: Entry[] = [];
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
      const token: UndoToken = Symbol('undo-entry');
      past = [...past, { token, snapshot }];
      future = [];
      set({ canUndo: true, canRedo: false });
      return token;
    },

    _discard: (token, revert = (snapshot) => snapshot) => {
      const at = past.findIndex((entry) => entry.token === token);
      if (at === -1) return;
      past = [
        ...past.slice(0, at),
        ...past.slice(at + 1).map((entry) => ({ ...entry, snapshot: revert(entry.snapshot) })),
      ];
      future = future.map(revert);
      set({ canUndo: past.length > 0 });
    },

    undo: () => {
      if (past.length === 0) return;
      const current = getState();
      const previous = past[past.length - 1]!;
      past = past.slice(0, -1);
      future = [current, ...future];
      setState(previous.snapshot);
      set({ canUndo: past.length > 0, canRedo: true });
    },

    redo: () => {
      if (future.length === 0) return;
      const current = getState();
      const next = future[0]!;
      future = future.slice(1);
      past = [...past, { token: Symbol('undo-entry'), snapshot: current }];
      setState(next);
      set({ canUndo: true, canRedo: future.length > 0 });
    },
  }));
}
