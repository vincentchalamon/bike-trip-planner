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

describe('useUndoRedo (#1178, #1166 write gate)', () => {
  it('exposes canUndo when online and history exists', () => {
    const { result, unmount } = renderHook();
    expect(result.current.canUndo).toBe(true);
    unmount();
  });

  it('disables and refuses undo/redo while offline', () => {
    act(() => useOfflineStore.setState({ isOnline: false }));
    const { result, unmount } = renderHook();

    expect(result.current.canUndo).toBe(false);
    expect(result.current.canRedo).toBe(false);

    // Refused: calling undo does not touch the temporal history.
    act(() => result.current.undo());
    expect(useTripTemporalStore.getState().canUndo).toBe(true);
    unmount();
  });

  it('disables undo/redo when the API is unreachable', () => {
    act(() => useOfflineStore.setState({ apiReachable: false }));
    const { result, unmount } = renderHook();
    expect(result.current.canUndo).toBe(false);
    unmount();
  });

  it('disables undo/redo on a locked (started) trip', () => {
    act(() => useTripStore.setState({ isLocked: true }));
    const { result, unmount } = renderHook();
    expect(result.current.canUndo).toBe(false);
    unmount();
  });
});
