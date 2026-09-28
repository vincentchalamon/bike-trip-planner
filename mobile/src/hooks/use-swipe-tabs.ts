import { useCallback, useMemo, useState } from 'react';
import { PanResponder } from 'react-native';
import { swipeToView, type SwipeView } from '../lib/swipe';

// Roadbook / map tab state shared by the trip and shared-trip screens, with the
// horizontal swipe that mirrors the segmented control. `hasViewedMap` latches on
// the first switch to the map, so the map mounts only once it is actually viewed
// (ADR-057: the route is fetched on demand) and then stays mounted (#1176).
// Latched in the setter rather than in an effect, so the first map frame is not
// rendered empty.
export function useSwipeTabs() {
  const [tabs, setTabs] = useState<{ view: SwipeView; hasViewedMap: boolean }>({
    view: 'roadbook',
    hasViewedMap: false,
  });
  const setView = useCallback(
    (view: SwipeView) => setTabs((s) => ({ view, hasViewedMap: s.hasViewedMap || view === 'map' })),
    [],
  );

  // Claims only a decisive horizontal gesture so the roadbook's vertical scroll
  // (and, over the native map, its own pan) keep working.
  const panResponder = useMemo(
    () =>
      PanResponder.create({
        onMoveShouldSetPanResponder: (_, g) =>
          Math.abs(g.dx) > 24 && Math.abs(g.dx) > Math.abs(g.dy) * 1.5,
        onPanResponderRelease: (_, g) => {
          const next = swipeToView(g.dx);
          if (next) setView(next);
        },
      }),
    [setView],
  );

  return {
    view: tabs.view,
    setView,
    hasViewedMap: tabs.hasViewedMap,
    panHandlers: panResponder.panHandlers,
  };
}
