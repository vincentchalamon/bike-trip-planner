/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { useSwipeTabs } from './use-swipe-tabs';

function mount() {
  const seen: ReturnType<typeof useSwipeTabs>[] = [];
  function Probe() {
    seen.push(useSwipeTabs());
    return null;
  }
  let renderer!: ReturnType<typeof TestRenderer.create>;
  act(() => {
    renderer = TestRenderer.create(createElement(Probe));
  });
  return { seen, latest: () => seen[seen.length - 1]!, unmount: () => act(() => renderer.unmount()) };
}

describe('useSwipeTabs', () => {
  it('starts on the roadbook with the map never viewed', () => {
    const { latest, unmount } = mount();
    expect(latest().view).toBe('roadbook');
    expect(latest().hasViewedMap).toBe(false);
    unmount();
  });

  // The map must be mounted in the very render that shows it, not one effect later.
  it('latches hasViewedMap in the same render that switches to the map', () => {
    const { seen, latest, unmount } = mount();
    act(() => latest().setView('map'));

    expect(seen.find((s) => s.view === 'map')!.hasViewedMap).toBe(true);
    unmount();
  });

  it('keeps the map viewed after going back to the roadbook', () => {
    const { latest, unmount } = mount();
    act(() => latest().setView('map'));
    act(() => latest().setView('roadbook'));

    expect(latest().view).toBe('roadbook');
    expect(latest().hasViewedMap).toBe(true);
    unmount();
  });

  it('exposes pan handlers that stay stable across renders', () => {
    const { seen, latest, unmount } = mount();
    const first = seen[0]!.panHandlers;
    act(() => latest().setView('map'));

    expect(typeof first.onResponderRelease).toBe('function');
    expect(latest().panHandlers).toBe(first);
    unmount();
  });
});
