import { describe, it, expect } from "vitest";
import { createTemporalStore } from "./temporal-middleware";

interface State {
  x: number;
  y: number;
}

function history() {
  let state: State = { x: 0, y: 0 };
  const store = createTemporalStore(
    () => state,
    (snapshot) => {
      state = snapshot as State;
    },
  );

  return {
    store,
    get state() {
      return state;
    },
    set state(next: State) {
      state = next;
    },
    /** Record the current state as the pre-edit snapshot, then apply the edit. */
    edit(next: Partial<State>) {
      const token = store.getState()._push(state);
      state = { ...state, ...next };
      return token;
    },
  };
}

describe("createTemporalStore _discard", () => {
  it("withdraws the refused entry even when accepted ones sit on top, and scrubs its value from them", () => {
    const h = history();
    const refused = h.edit({ x: 1 });
    h.edit({ y: 1 });
    h.edit({ y: 2 });

    // The refused edit is rolled back in the live state and in the history.
    h.state = { ...h.state, x: 0 };
    h.store.getState()._discard(refused, (s) => ({ ...(s as State), x: 0 }));

    h.store.getState().undo();
    expect(h.state).toEqual({ x: 0, y: 1 });
    h.store.getState().undo();
    expect(h.state).toEqual({ x: 0, y: 0 });
    expect(h.store.getState().canUndo).toBe(false);
  });

  it("leaves the entries older than the refused one untouched", () => {
    const h = history();
    h.edit({ y: 1 });
    const refused = h.edit({ x: 1 });

    h.state = { ...h.state, x: 0 };
    h.store.getState()._discard(refused, () => ({ x: 9, y: 9 }));

    h.store.getState().undo();
    expect(h.state).toEqual({ x: 0, y: 0 });
    expect(h.store.getState().canUndo).toBe(false);
  });

  it("is a no-op for an entry that is already gone", () => {
    const h = history();
    const token = h.edit({ x: 1 });
    h.store.getState().undo();

    h.store.getState()._discard(token);

    expect(h.store.getState().canRedo).toBe(true);
    h.store.getState().redo();
    expect(h.state).toEqual({ x: 1, y: 0 });
  });
});
