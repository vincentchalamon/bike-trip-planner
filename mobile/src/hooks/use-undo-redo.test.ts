/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { useUndoRedo } from './use-undo-redo';
import { useTripStore, useTripTemporalStore } from '../store/trip-store';
import { useOfflineStore } from '../store/offline-store';

type UseUndoRedo = ReturnType<typeof useUndoRedo>;

// Minimal renderHook on react-test-renderer (the mobile convention, no RTL).
function renderHook(): { result: { current: UseUndoRedo }; unmount: () => void } {
  const result = { current: undefined as unknown as UseUndoRedo };
  function Probe() {
    result.current = useUndoRedo();
    return null;
  }
  let renderer!: ReturnType<typeof TestRenderer.create>;
  act(() => {
    renderer = TestRenderer.create(createElement(Probe));
  });
  return { result, unmount: () => act(() => renderer.unmount()) };
}

beforeEach(() => {
  useTripStore.getState().reset();
  useTripTemporalStore.getState().clear();
  useOfflineStore.setState({ isOnline: true, apiReachable: true });
  // Arm a single undoable entry so canUndo would be true absent any gate.
  act(() => useTripTemporalStore.getState()._push({ marker: true }));
});

describe('useUndoRedo (#1178, local-first + #1166 lock gate)', () => {
  it('exposes canUndo when history exists', () => {
    const { result, unmount } = renderHook();
    expect(result.current.canUndo).toBe(true);
    unmount();
  });

  it('stays available offline — undo/redo replay local state, no API call (web parity)', () => {
    act(() => useOfflineStore.setState({ isOnline: false, apiReachable: false }));
    const { result, unmount } = renderHook();

    expect(result.current.canUndo).toBe(true);
    // And it actually runs: the history pointer advances.
    act(() => result.current.undo());
    expect(useTripTemporalStore.getState().canUndo).toBe(false);
    unmount();
  });

  it('is not blocked by an out-of-zone trip (undo/redo never reroute)', () => {
    act(() => useTripStore.setState({ outOfZone: true }));
    const { result, unmount } = renderHook();
    expect(result.current.canUndo).toBe(true);
    unmount();
  });

  it('disables and refuses undo/redo on a locked (started) trip', () => {
    act(() => useTripStore.setState({ isLocked: true }));
    const { result, unmount } = renderHook();

    expect(result.current.canUndo).toBe(false);
    expect(result.current.canRedo).toBe(false);

    // Refused: calling undo does not touch the temporal history.
    act(() => result.current.undo());
    expect(useTripTemporalStore.getState().canUndo).toBe(true);
    unmount();
  });
});
