import { api } from './client';
import type { components } from '@btp/core/schema';

export type TripListItem = components['schemas']['Trip.TripListItem.jsonld'];
export type TripDetail = components['schemas']['TripDetail.jsonld'];
export type Stage = NonNullable<TripDetail['stages']>[number];
export type Coordinate = components['schemas']['Coordinate'];
export type TripConfigPatch =
  components['schemas']['Trip.TripRequest.jsonMergePatch'];
export type TripModification = components['schemas']['TripModification'];

/** Raw HTTP outcome of a mutating call, so callers can normalize per status. */
export interface MutationResult {
  ok: boolean;
  status: number;
}

const ld = { Accept: 'application/ld+json' };
// Body-bearing POSTs negotiate on JSON-LD; PATCHes use JSON Merge Patch (the
// backend PATCH operations declare `application/merge-patch+json`).
const ldBody = {
  Accept: 'application/ld+json',
  'Content-Type': 'application/ld+json',
};
const mergePatch = {
  Accept: 'application/ld+json',
  'Content-Type': 'application/merge-patch+json',
};

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

// ---------------------------------------------------------------------------
// Trip-config mutations (#1031). Each returns the raw {ok,status} so a runner
// can normalize the failure (see gating.ts) and roll back an optimistic edit.
// ---------------------------------------------------------------------------

/** PATCH trip config (dates / pacing / title / accommodation types). */
export async function updateTripConfig(
  tripId: string,
  body: TripConfigPatch,
): Promise<MutationResult> {
  const { response } = await api.PATCH('/trips/{id}', {
    params: { path: { id: tripId } },
    headers: mergePatch,
    body,
  });
  return { ok: response.ok, status: response.status };
}

/** Add a manual stage at `position` (0-based), routed via Valhalla. */
export async function createStage(
  tripId: string,
  body: { position: number; startPoint: Coordinate; endPoint: Coordinate },
): Promise<MutationResult> {
  const { response } = await api.POST('/trips/{tripId}/stages', {
    params: { path: { tripId } },
    headers: ldBody,
    body,
  });
  return { ok: response.ok, status: response.status };
}

/** Update a stage's distance (re-splits from this stage onward). */
export async function updateStageDistance(
  tripId: string,
  index: number,
  distance: number,
): Promise<MutationResult> {
  const { response } = await api.PATCH('/trips/{tripId}/stages/{index}', {
    params: { path: { tripId, index: String(index) } },
    headers: mergePatch,
    body: { distance },
  });
  return { ok: response.ok, status: response.status };
}

/** Move a stage to a new position. */
export async function moveStage(
  tripId: string,
  index: number,
  toIndex: number,
): Promise<MutationResult> {
  const { response } = await api.PATCH('/trips/{tripId}/stages/{index}/move', {
    params: { path: { tripId, index: String(index) } },
    headers: mergePatch,
    body: { toIndex },
  });
  return { ok: response.ok, status: response.status };
}

/** Insert a rest day after `index` (dates shift by one day server-side). */
export async function insertRestDay(
  tripId: string,
  index: number,
): Promise<MutationResult> {
  const { response } = await api.POST('/trips/{tripId}/stages/{index}/rest-day', {
    params: { path: { tripId, index: String(index) } },
    headers: ld,
  });
  return { ok: response.ok, status: response.status };
}

/**
 * Select (or, with both coords null, deselect) an accommodation for a stage.
 * A concurrent scan invalidates the list and the backend answers 409.
 */
export async function setStageAccommodation(
  tripId: string,
  index: number,
  lat: number | null,
  lon: number | null,
): Promise<MutationResult> {
  const { response } = await api.PATCH(
    '/trips/{tripId}/stages/{index}/accommodation',
    {
      params: { path: { tripId, index: String(index) } },
      headers: mergePatch,
      body: { selectedAccommodationLat: lat, selectedAccommodationLon: lon },
    },
  );
  return { ok: response.ok, status: response.status };
}

/** Insert a cultural POI as a waypoint, re-routing the stage via Valhalla. */
export async function addPoiWaypoint(
  tripId: string,
  index: number,
  waypointLat: number,
  waypointLon: number,
): Promise<MutationResult> {
  const { response } = await api.POST(
    '/trips/{tripId}/stages/{index}/poi-waypoint',
    {
      params: { path: { tripId, index: String(index) } },
      headers: ldBody,
      body: { waypointLat, waypointLon },
    },
  );
  return { ok: response.ok, status: response.status };
}

/** Re-scan accommodations (all stages, or a single one) with a custom radius. */
export async function scanAccommodations(
  tripId: string,
  radiusKm: number,
  stageIndex?: number,
): Promise<MutationResult> {
  const { response } = await api.POST('/trips/{tripId}/accommodations/scan', {
    params: { path: { tripId } },
    headers: ldBody,
    body: { radiusKm, ...(stageIndex !== undefined && { stageIndex }) },
  });
  return { ok: response.ok, status: response.status };
}

/** Apply a batch of queued modifications in a single recompute pass. */
export async function applyBatchRecompute(
  tripId: string,
  modifications: TripModification[],
): Promise<MutationResult> {
  const { response } = await api.POST('/trips/{id}/recompute', {
    params: { path: { id: tripId } },
    headers: ldBody,
    body: { modifications },
  });
  return { ok: response.ok, status: response.status };
}

/** Re-run the full enrichment pipeline (POIs, weather, terrain, …). */
export async function analyzeTrip(tripId: string): Promise<MutationResult> {
  const { response } = await api.POST('/trips/{id}/analyze', {
    params: { path: { id: tripId } },
    headers: ld,
  });
  return { ok: response.ok, status: response.status };
}

/** Deep-clone a trip. Returns the new trip id, or null on failure. */
export async function duplicateTrip(tripId: string): Promise<string | null> {
  const { data, error } = await api.POST('/trips/{id}/duplicate', {
    params: { path: { id: tripId } },
    headers: ld,
  });
  if (error || !data?.id) {
    return null;
  }
  return data.id;
}

/** Permanently delete a trip and all its stages. */
export async function deleteTrip(tripId: string): Promise<MutationResult> {
  const { response } = await api.DELETE('/trips/{id}', {
    params: { path: { id: tripId } },
    headers: ld,
  });
  return { ok: response.ok, status: response.status };
}
