import { useMemo } from 'react';
import { View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { StageData } from '@btp/core';
import { EmptyState } from '../ui';
import { TripMap } from '../TripMap';
import { PoiWaypointPopover } from './PoiWaypointPopover';
import { collectMarkers, type MapMarker } from '../map/map-utils';
import { stageColor } from '../map/stage-colors';
import { stageGeometryCoords } from './stage-detail';

// The stage's own map, with the alert-highlighted stretch and the POI popover
// (#1179) that adds a tapped POI as a route waypoint when rerouting is allowed.
export function StageMapPanel({
  stage,
  highlightedSegment,
  selectedPoi,
  canReroute,
  onSelectPoi,
  onAddPoi,
}: {
  stage: StageData;
  highlightedSegment: [number, number][] | undefined;
  selectedPoi: MapMarker | null;
  canReroute: boolean;
  onSelectPoi: (poi: MapMarker | null) => void;
  onAddPoi: (poi: MapMarker) => void;
}) {
  const { t } = useTranslation();
  const coordinates = useMemo(() => stageGeometryCoords(stage), [stage]);
  // One colored line for this stage, keyed on its dayNumber, so its color
  // matches the trip map's stage coloring (see stageColor). Built directly
  // (not via buildStageLines, which drops rest days for the multi-stage
  // overview map) so a rest day's own point(s) still render here.
  const stageSegments = useMemo(
    () =>
      coordinates.length >= 2 ? [{ color: stageColor(stage.dayNumber ?? 0), coordinates }] : [],
    [stage, coordinates],
  );
  const markers = useMemo(() => collectMarkers([stage]), [stage]);

  return (
    <View style={{ height: 220 }}>
      {coordinates.length > 0 ? (
        <>
          <TripMap
            stageSegments={stageSegments}
            markers={markers}
            highlightedSegment={highlightedSegment}
            onSelectPoi={canReroute ? onSelectPoi : undefined}
          />
          {selectedPoi ? (
            <PoiWaypointPopover
              poi={selectedPoi}
              disabled={!canReroute}
              onAdd={() => onAddPoi(selectedPoi)}
              onClose={() => onSelectPoi(null)}
            />
          ) : null}
        </>
      ) : (
        <View style={{ flex: 1 }}>
          <EmptyState title={t('trip.mapEmpty')} />
        </View>
      )}
    </View>
  );
}
