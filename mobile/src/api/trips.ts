import { api } from './client';
import type { components } from './schema';

export type TripListItem = components['schemas']['Trip.TripListItem.jsonld'];
export type TripDetail = components['schemas']['TripDetail.jsonld'];
export type Stage = NonNullable<TripDetail['stages']>[number];

const ld = { Accept: 'application/ld+json' };

export async function fetchTrips(): Promise<TripListItem[]> {
  // openapi-fetch resolves (never rejects) on a non-2xx, returning `error`. Throw
  // so callers can tell a real backend failure from a legitimately empty list.
  const { data, error } = await api.GET('/trips', { headers: ld });
  if (error) {
    throw new Error('Failed to fetch trips');
  }
  return data?.member ?? [];
}

export async function fetchTripDetail(id: string): Promise<TripDetail | null> {
  const { data, error } = await api.GET('/trips/{id}/detail', {
    params: { path: { id } },
    headers: ld,
  });
  if (error) {
    throw new Error('Failed to fetch trip detail');
  }
  return data ?? null;
}

// Flatten every stage geometry point into a single [lon, lat] polyline for the map.
export function tripCoordinates(detail: TripDetail): [number, number][] {
  const coords: [number, number][] = [];
  for (const stage of detail.stages ?? []) {
    for (const point of stage.geometry ?? []) {
      if (typeof point.lon === 'number' && typeof point.lat === 'number') {
        coords.push([point.lon, point.lat]);
      }
    }
  }
  return coords;
}
