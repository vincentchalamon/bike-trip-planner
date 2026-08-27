import { api } from './client';
import { WEB_BASE_URL } from './config';
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

export const TRIPS_PAGE_SIZE = 12;

/** Server-side filters exposed by `GET /trips` (partial title, date range). */
export interface TripFilters {
  title?: string;
  startDate?: string;
  endDate?: string;
}

/** One page of the trip collection plus Hydra's total count (for pagination). */
export interface TripsPage {
  items: TripListItem[];
  totalItems: number;
}

export async function fetchTrips(
  page = 1,
  filters: TripFilters = {},
): Promise<TripsPage> {
  // The API paginates and filters server-side (title partial match, startDate /
  // endDate range) — see the `api_trips_get_collection` query params. Empty
  // filters are omitted so the backend does not treat "" as a match constraint.
  const query: Record<string, string | number> = {
    page,
    itemsPerPage: TRIPS_PAGE_SIZE,
  };
  if (filters.title) query.title = filters.title;
  if (filters.startDate) query.startDate = filters.startDate;
  if (filters.endDate) query.endDate = filters.endDate;

  // openapi-fetch resolves (never rejects) on a non-2xx, returning `error`. Throw
  // so callers can tell a real backend failure from a legitimately empty list.
  const { data, error } = await api.GET('/trips', { params: { query }, headers: ld });
  if (error) {
    throw new Error('Failed to fetch trips');
  }
  return { items: data?.member ?? [], totalItems: data?.totalItems ?? 0 };
}

/**
 * Every trip (all pages), unfiltered. Used only to schedule local notifications,
 * which must cover trips beyond the paginated list's first page — a trip on page
 * 2+ would otherwise never get its reminders. The UI list stays paginated.
 */
export async function fetchAllTrips(): Promise<TripListItem[]> {
  const first = await fetchTrips(1, {});
  const all = [...first.items];
  const totalPages = Math.max(1, Math.ceil(first.totalItems / TRIPS_PAGE_SIZE));
  for (let page = 2; page <= totalPages; page += 1) {
    const { items } = await fetchTrips(page, {});
    all.push(...items);
  }
  return all;
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

// All-stages geometry for the map (ADR-057), fetched on demand off the summary.
export type TripRoute = components['schemas']['TripRoute.jsonld'];

export async function fetchTripRoute(id: string): Promise<TripRoute | null> {
  const { data, error } = await api.GET('/trips/{id}/route', {
    params: { path: { id } },
    headers: ld,
  });
  if (error) {
    throw new Error('Failed to fetch trip route');
  }
  return data ?? null;
}

// One stage in full (geometry, resupply, accommodations, events, alerts).
export type StageDetail = components['schemas']['Stage.StageResponse.jsonld'];

export async function fetchStageDetail(
  tripId: string,
  index: number,
): Promise<StageDetail | null> {
  const { data, error } = await api.GET('/trips/{tripId}/stages/{index}/detail', {
    params: { path: { tripId, index: String(index) } },
    headers: ld,
  });
  if (error) {
    throw new Error('Failed to fetch stage detail');
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

/**
 * Add a manually-entered (hors-app) accommodation to a stage. The backend
 * geocodes the address into coordinates, makes it the selected accommodation and
 * moves the stage boundary to it (same downstream as selecting a scanned entry).
 * A 422 status means the address could not be geocoded.
 */
export async function addManualAccommodation(
  tripId: string,
  index: number,
  body: {
    name: string;
    address: string;
    priceTotal: number | null;
    url: string | null;
  },
): Promise<MutationResult> {
  const { response } = await api.POST(
    '/trips/{tripId}/stages/{index}/accommodations/manual',
    {
      params: { path: { tripId, index: String(index) } },
      headers: ldBody,
      body,
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

// The backend TripRequest requires the whole pacing block, so a fresh trip
// created from a link ships the schema defaults; the rider tunes pacing later
// from the roadbook. Mirrors the web create payload (use-trip-planner).
const CREATE_DEFAULTS = {
  fatigueFactor: 0.9,
  elevationPenalty: 50,
  ebikeMode: false,
  departureHour: 8,
  maxDistancePerDay: 80,
  averageSpeed: 15,
  enabledAccommodationTypes: [
    'camp_site',
    'hostel',
    'alpine_hut',
    'chalet',
    'guest_house',
    'hotel',
    'wilderness_hut',
  ],
};

/**
 * Create a trip from a supported source URL (Komoot / Strava / RideWithGPS). The
 * backend accepts asynchronously (202) and starts parsing + computing the route;
 * the caller follows the pipeline over SSE. Returns the new trip id (null on
 * failure) plus the raw status so a rejected / unsupported URL can be classified.
 */
export async function createTrip(
  sourceUrl: string,
): Promise<{ id: string | null; status: number }> {
  const { data, response } = await api.POST('/trips', {
    headers: ldBody,
    body: { sourceUrl, ...CREATE_DEFAULTS },
  });
  return { id: data?.id ?? null, status: response.status };
}

/** A GPX file picked on-device (expo-document-picker asset shape). */
export interface GpxFile {
  uri: string;
  name: string;
  mimeType?: string;
}

/**
 * Create a trip from a GPX file (multipart POST /trips/gpx-upload). Mirrors the
 * web drag&drop upload: the backend parses the GPX and dispatches the async
 * computations (202); the caller follows the pipeline over SSE. Returns the new
 * trip id (null on failure) plus the raw status so a rejected file (400/422) can
 * be classified. openapi-fetch passes a FormData body through untouched and lets
 * fetch set the multipart boundary; the RN file part is `{ uri, name, type }`.
 */
export async function uploadGpx(
  file: GpxFile,
): Promise<{ id: string | null; status: number }> {
  const formData = new FormData();
  formData.append('gpxFile', {
    uri: file.uri,
    name: file.name,
    type: file.mimeType ?? 'application/gpx+xml',
  } as unknown as Blob);
  const { data, response } = await api.POST('/trips/gpx-upload', {
    body: formData as never,
  });
  return { id: data?.id ?? null, status: response.status };
}

// ---------------------------------------------------------------------------
// GPX/FIT export (#1047): both endpoints negotiate on Accept (no `.gpx`/`.fit`
// path suffix in the OpenAPI-typed paths) and return binary/text content, not
// JSON — `parseAs: 'arrayBuffer'` reads the raw bytes instead of the default
// JSON parse, while still going through the auth middleware in `client.ts`.
// ---------------------------------------------------------------------------

export type ExportFormat = 'gpx' | 'fit';

const EXPORT_ACCEPT: Record<ExportFormat, string> = {
  gpx: 'application/gpx+xml',
  fit: 'application/vnd.ant.fit',
};

/** Sanitize a trip title into a filesystem-safe base name (mirrors the pwa helper). */
function sanitizeExportFileBase(title: string): string {
  return title.trim().replace(/[^a-z0-9-_]/gi, '-') || 'trip';
}

/** e.g. "Entre Sensée et Escaut" + gpx -> "Entre-Sens-e-et-Escaut.gpx". */
export function tripExportFileName(tripTitle: string, format: ExportFormat): string {
  return `${sanitizeExportFileBase(tripTitle)}.${format}`;
}

/** e.g. "Entre Sensée et Escaut" + day 1 + fit -> "Entre-Sens-e-et-Escaut-stage-1.fit". */
export function stageExportFileName(
  tripTitle: string,
  dayNumber: number,
  format: ExportFormat,
): string {
  return `${sanitizeExportFileBase(tripTitle)}-stage-${dayNumber}.${format}`;
}

/** Download the full trip as a single GPX/FIT file (all stages merged). */
export async function fetchTripExport(
  tripId: string,
  format: ExportFormat,
): Promise<ArrayBuffer> {
  const { data, error, response } = await api.GET('/trips/{id}', {
    params: { path: { id: tripId } },
    headers: { Accept: EXPORT_ACCEPT[format] },
    parseAs: 'arrayBuffer',
  });
  if (error || !response.ok) {
    throw new Error('Failed to export trip');
  }
  return data;
}

/**
 * Download a single stage as GPX/FIT. The `{index}` path segment actually
 * resolves on the 1-based `dayNumber` server-side (Stage.php's Link targets
 * `dayNumber`, not the 0-based array position) — pass `dayNumber`.
 */
export async function fetchStageExport(
  tripId: string,
  dayNumber: number,
  format: ExportFormat,
): Promise<ArrayBuffer> {
  const { data, error, response } = await api.GET('/trips/{tripId}/stages/{index}/export', {
    params: { path: { tripId, index: String(dayNumber) } },
    headers: { Accept: EXPORT_ACCEPT[format] },
    parseAs: 'arrayBuffer',
  });
  if (error || !response.ok) {
    throw new Error('Failed to export stage');
  }
  return data;
}

// ---------------------------------------------------------------------------
// Public share link (#1048). A read-only `/s/<code>` link the rider can create
// and revoke; the shared SSR page is web-only (mobile only manages the link).
// ---------------------------------------------------------------------------

export type TripShareResponse = components['schemas']['TripShare.jsonld'];

/** Active share link for a trip, or null when none exists (or on error). */
export async function getTripShare(
  tripId: string,
): Promise<TripShareResponse | null> {
  const { data, error } = await api.GET('/trips/{tripId}/share', {
    params: { path: { tripId } },
    headers: ld,
  });
  if (error) {
    return null;
  }
  return data ?? null;
}

/** Create a read-only share link for a trip, or null on failure. */
export async function createTripShare(
  tripId: string,
): Promise<TripShareResponse | null> {
  const { data, error } = await api.POST('/trips/{tripId}/share', {
    params: { path: { tripId } },
    headers: ldBody,
    body: {},
  });
  if (error) {
    return null;
  }
  return data ?? null;
}

/** Revoke the active share link (soft delete). Returns true on success. */
export async function revokeTripShare(tripId: string): Promise<boolean> {
  const { response } = await api.DELETE('/trips/{tripId}/share', {
    params: { path: { tripId } },
    headers: ld,
  });
  return response.ok;
}

/**
 * Build the public web share URL from a short code. The `/s/<code>` page is
 * rendered by the web frontend, so the origin comes from WEB_BASE_URL
 * (EXPO_PUBLIC_WEB_URL), which fails closed in non-dev builds when unset — a
 * missing origin surfaces loudly instead of emitting a dead link.
 */
export function buildShareUrl(shortCode: string): string {
  const base = WEB_BASE_URL.replace(/\/+$/, '');
  return `${base}/s/${encodeURIComponent(shortCode)}`;
}

// ---------------------------------------------------------------------------
// Anonymous shared-trip consultation (#1177). The `/s/<code>` endpoints require
// no auth (the JWT header, when present, is simply ignored server-side), and
// serve a read-only projection of the trip. Mirrors the web's fetchSharedTrip /
// fetchSharedTripRoute / downloadSharedTripFile (pwa's api/client).
// ---------------------------------------------------------------------------

export type SharedTripDetail = components['schemas']['TripShare.TripDetail.jsonld'];

/** Fetch a shared trip via its short code. Null when invalid / revoked. */
export async function fetchSharedTrip(
  shortCode: string,
): Promise<SharedTripDetail | null> {
  const { data, error } = await api.GET('/s/{shortCode}', {
    params: { path: { shortCode } },
    headers: ld,
  });
  if (error) {
    return null;
  }
  return data ?? null;
}

/** Fetch a shared trip's all-stages geometry (ADR-057), by short code. */
export async function fetchSharedTripRoute(
  shortCode: string,
): Promise<TripRoute | null> {
  const { data, error } = await api.GET('/s/{shortCode}/route', {
    params: { path: { shortCode } },
    headers: ld,
  });
  if (error) {
    return null;
  }
  return data ?? null;
}

// The share downloads sit at `/s/{shortCode}.gpx` / `.fit` (literal suffix in the
// OpenAPI paths, unlike the auth'd exports which negotiate on Accept).
const SHARED_EXPORT_PATH: Record<ExportFormat, '/s/{shortCode}.gpx' | '/s/{shortCode}.fit'> = {
  gpx: '/s/{shortCode}.gpx',
  fit: '/s/{shortCode}.fit',
};

/** Download a shared trip as GPX/FIT via short code (all stages merged). */
export async function fetchSharedTripExport(
  shortCode: string,
  format: ExportFormat,
): Promise<ArrayBuffer> {
  const { data, error, response } = await api.GET(SHARED_EXPORT_PATH[format], {
    params: { path: { shortCode } },
    headers: { Accept: EXPORT_ACCEPT[format] },
    parseAs: 'arrayBuffer',
  });
  if (error || !response.ok) {
    throw new Error('Failed to export shared trip');
  }
  return data;
}
