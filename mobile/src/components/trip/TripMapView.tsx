import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../ui';
import { TripMap } from '../TripMap';
import { useTripStore } from '../../store/trip-store';

// The map tab: derives the route polyline from the store's stages and hands it to
// the shared TripMap, or shows the empty state when there is no geometry yet.
// #1040 layers markers (accommodations, POIs, alerts) onto this view.
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

  if (coordinates.length === 0) {
    return <EmptyState title={t('trip.mapEmpty')} />;
  }
  return <TripMap coordinates={coordinates} />;
}
