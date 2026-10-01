/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { useStageCursor } from './use-stage-cursor';
import type { MapMarker } from '../components/map/map-utils';

type Cursor = ReturnType<typeof useStageCursor>;

const SEGMENT: [number, number][] = [
  [2.35, 48.85],
  [2.36, 48.86],
];
const POI = { lat: 48.85, lon: 2.35 } as MapMarker;

function mount(initialIndex: number, count: number) {
  const result: { current: Cursor } = { current: undefined as unknown as Cursor };
  function Probe({ count: c }: { count: number }) {
    result.current = useStageCursor(initialIndex, c);
    return null;
  }
  let renderer!: ReturnType<typeof TestRenderer.create>;
  act(() => {
    renderer = TestRenderer.create(createElement(Probe, { count }));
  });
  return {
    result,
    setCount: (c: number) => act(() => renderer.update(createElement(Probe, { count: c }))),
    unmount: () => act(() => renderer.unmount()),
  };
}

describe('useStageCursor', () => {
  it('starts on the resolved stage, or the first one when unresolved', () => {
    const found = mount(2, 4);
    expect(found.result.current.stageIndex).toBe(2);
    found.unmount();

    const missing = mount(-1, 4);
    expect(missing.result.current.stageIndex).toBe(0);
    missing.unmount();
  });

  it('clamps the cursor as the stage count changes under it', () => {
    const { result, setCount, unmount } = mount(3, 4);
    setCount(2);
    expect(result.current.stageIndex).toBe(1);
    setCount(0);
    expect(result.current.stageIndex).toBe(0);
    unmount();
  });

  it('drops the highlight and the tapped POI when navigating to another stage', () => {
    const { result, unmount } = mount(0, 3);
    act(() => {
      result.current.setHighlightedSegment(SEGMENT);
      result.current.setSelectedPoi(POI);
    });
    expect(result.current.highlightedSegment).toBe(SEGMENT);
    expect(result.current.selectedPoi).toBe(POI);

    act(() => result.current.goTo(1));
    expect(result.current.stageIndex).toBe(1);
    expect(result.current.highlightedSegment).toBeUndefined();
    expect(result.current.selectedPoi).toBeNull();

    // Coming back does not resurrect the previous stage's state.
    act(() => result.current.goTo(0));
    expect(result.current.highlightedSegment).toBeUndefined();
    unmount();
  });

  it('keeps the highlight when re-selecting the current stage', () => {
    const { result, unmount } = mount(1, 3);
    act(() => result.current.setHighlightedSegment(SEGMENT));
    act(() => result.current.goTo(1));
    expect(result.current.highlightedSegment).toBe(SEGMENT);
    unmount();
  });

  it('drops stage-scoped state when a clamp moves the cursor, and scopes new state to it', () => {
    const { result, setCount, unmount } = mount(2, 3);
    act(() => result.current.setSelectedPoi(POI));
    setCount(2);
    expect(result.current.stageIndex).toBe(1);
    expect(result.current.selectedPoi).toBeNull();

    act(() => result.current.setHighlightedSegment(SEGMENT));
    expect(result.current.highlightedSegment).toBe(SEGMENT);
    // The cursor is now pinned to the clamped stage: growing back keeps it there.
    setCount(3);
    expect(result.current.stageIndex).toBe(1);
    expect(result.current.highlightedSegment).toBe(SEGMENT);
    unmount();
  });
});
