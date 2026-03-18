"use client";

/**
 * Lightweight temporal (undo/redo) middleware for Zustand + Immer.
 *
 * Works by storing deep snapshots of a selected slice of state before each
 * tracked mutation. Snapshots are plain JSON-serialisable objects — no Immer
 * draft proxies leak into the history stack.
 *
 * Usage:
 *   Call `createTemporalStore(getState, setState)` alongside your host store.
 *   The companion temporal store exposes:
 *     - `undo()` — restores the previous snapshot
 *     - `redo()` — re-applies the next snapshot
 *     - `canUndo` — whether there is history to undo
 *     - `canRedo` — whether there is history to redo
 *     - `clear()` — wipes all history (e.g. when loading a new trip)
 *
 * Implementation notes:
 *   • History limit: MAX_HISTORY entries (oldest are evicted).
 *   • Snapshots are pushed *before* each tracked mutation by calling `_push()`
 *     explicitly inside the store action.  This avoids capturing Immer draft
 *     proxies and ensures only intentional user actions are tracked.
 *   • `redo` stack is cleared whenever a new user action is recorded.
 */

import { create } from "zustand";

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
}

/**
 * Creates the companion temporal store bound to a host Zustand store.
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

  const store = create<TemporalState>()((set) => ({
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

  return store;
}
