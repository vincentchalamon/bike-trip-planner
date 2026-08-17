import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../ui';
import { TripMap } from '../TripMap';
import { collectMarkers } from '../map/map-utils';
import { useTripStore } from '../../store/trip-store';

// The map tab: derives the route polyline and markers from the store's stages
// and hands them to the shared TripMap, or shows the empty state when there is
// no geometry yet. #1040 adds base-map toggle, markers and fit-bounds.
export function TripMapView() {
  const { t } = useTranslation();
  const stages = useTripStore((s) => s.stages);

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

  if (coordinates.length === 0) {
    return <EmptyState title={t('trip.mapEmpty')} />;
  }
  return <TripMap coordinates={coordinates} markers={markers} />;
}
