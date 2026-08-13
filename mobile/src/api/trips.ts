import { api } from './client';
import type { components } from '@btp/core/schema';

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

// Delete a stage (merges it with the adjacent day). The backend recomputes and
// pushes the authoritative state over SSE; a started trip is rejected with 423
// (App\State\TripLocker). Returns the raw status so the caller can distinguish a
// lock (423) from any other failure and roll back its optimistic update.
export async function deleteStage(
  tripId: string,
  index: number,
): Promise<{ ok: boolean; status: number }> {
  const { response } = await api.DELETE('/trips/{tripId}/stages/{index}', {
    params: { path: { tripId, index: String(index) } },
    headers: ld,
  });
  return { ok: response.ok, status: response.status };
}
