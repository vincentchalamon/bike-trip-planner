import { useCallback, useState } from 'react';
import type { MapMarker } from '../components/map/map-utils';
import { clampIndex } from '../components/trip/stage-detail';

interface StageScope {
  // Stretch highlighted by an alert `navigate` action ([lon, lat] for the map).
  highlightedSegment: [number, number][] | undefined;
  // POI marker tapped on the stage map, for its "add to itinerary" popover (#1179).
  selectedPoi: MapMarker | null;
}

const BLANK: StageScope = { highlightedSegment: undefined, selectedPoi: null };

// Positional cursor of the stage detail screen. The index is clamped at read
// time, so it stays valid as stages hydrate or change under it without a sync
// effect. The highlight and the tapped POI belong to the stage they were set on:
// navigating drops them, and so does a clamp that moves the cursor (stages
// removed under it), since the stored index then no longer matches.
export function useStageCursor(initialIndex: number, count: number) {
  const [state, setState] = useState({ index: Math.max(0, initialIndex), scope: BLANK });
  const stageIndex = clampIndex(state.index, count);
  const scope = state.index === stageIndex ? state.scope : BLANK;

  const goTo = useCallback((index: number) => {
    setState((s) => (s.index === index ? s : { index, scope: BLANK }));
  }, []);

  const update = useCallback(
    (patch: Partial<StageScope>) => {
      setState((s) => {
        const index = clampIndex(s.index, count);
        return { index, scope: { ...(s.index === index ? s.scope : BLANK), ...patch } };
      });
    },
    [count],
  );

  const setHighlightedSegment = useCallback(
    (highlightedSegment: [number, number][] | undefined) => update({ highlightedSegment }),
    [update],
  );
  const setSelectedPoi = useCallback(
    (selectedPoi: MapMarker | null) => update({ selectedPoi }),
    [update],
  );

  return {
    stageIndex,
    highlightedSegment: scope.highlightedSegment,
    selectedPoi: scope.selectedPoi,
    goTo,
    setHighlightedSegment,
    setSelectedPoi,
  };
}
