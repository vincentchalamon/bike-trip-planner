import { useMemo, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { profileHighlightSegment } from '@btp/core/elevation';
import { EmptyState } from '../ui';
import { TripMap } from '../TripMap';
import { collectMarkers } from '../map/map-utils';
import { ElevationProfile } from './ElevationProfile';
import { useTripStore } from '../../store/trip-store';

// The map tab: derives the route polyline and markers from the store's stages
// and hands them to the shared TripMap, or shows the empty state when there is
// no geometry yet. #1040 adds base-map toggle, markers and fit-bounds; #1041
// stacks the elevation profile below and shares the hover state so a touch on the
// profile surlines the matching stretch on the map.
export function TripMapView() {
  const { t } = useTranslation();
  const stages = useTripStore((s) => s.stages);
  const [hover, setHover] = useState<{ coordIndex: number; stageIndex: number } | null>(
    null,
  );

  const coordinates = useMemo<[number, number][]>(() => {
    const coords: [number, number][] = [];
    for (const stage of stages) {
      for (const point of stage.geometry ?? []) {
        coords.push([point.lon, point.lat]);
      }
    }
    return coords;
  }, [stages]);

  const markers = useMemo(() => collectMarkers(stages), [stages]);

  const highlightedSegment = useMemo(
    () =>
      hover
        ? profileHighlightSegment(stages, hover.stageIndex, hover.coordIndex)
        : undefined,
    [hover, stages],
  );

  if (coordinates.length === 0) {
    return <EmptyState title={t('trip.mapEmpty')} />;
  }
  return (
    <View style={styles.container}>
      <View style={styles.map}>
        <TripMap
          coordinates={coordinates}
          markers={markers}
          highlightedSegment={highlightedSegment}
        />
      </View>
      <View style={styles.profile}>
        <ElevationProfile
          stages={stages}
          focusedStageIndex={null}
          onHover={(coordIndex, stageIndex) =>
            setHover(
              coordIndex === null || stageIndex === null
                ? null
                : { coordIndex, stageIndex },
            )
          }
        />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  map: { flex: 1 },
  profile: { padding: 8 },
});
