import { api } from './client';
import type { components } from '@btp/core/schema';

// The eight guided in-ride intents (ADR-048 §3). Derived from the generated
// schema so the union stays in lockstep with `App\InRide\InRidePoiCategory` — the
// backend is unchanged here, this only reads the shared type.
export type InRidePoiCategory =
  components['schemas']['PoiSuggestionDto.jsonld']['category'];

export type NearbyPoiSuggestion = components['schemas']['PoiSuggestionDto.jsonld'];

export type NearbyPoiSearchResponse =
  components['schemas']['Trip.NearbyPoiSearchResponse.jsonld'];

/**
 * Hard ceiling on the search radius (mirrors
 * `App\InRide\InRidePoiCategory::MAX_RADIUS_METERS` and the web
 * `MAX_RADIUS_METERS`). The "widen" affordance doubles the last effective radius
 * but never pushes past this bound.
 */
export const MAX_RADIUS_METERS = 20_000;

/**
 * Outcome of {@link searchNearbyPois}, discriminated so the caller maps the
 * backend's failure modes to localized messages without re-deriving them from a
 * raw status code (mirrors the web `NearbyPoiSearchResult`):
 *
 * - `ok`           — the search result (category, radius, POIs, coverage flags).
 * - `rate_limited` — 429: per-user in-ride search rate limit reached (transient).
 * - `network`      — the request never reached the backend (offline / DNS).
 * - `error`        — any other failure (4xx/5xx, empty body).
 */
export type NearbyPoiSearchResult =
  | { status: 'ok'; data: NearbyPoiSearchResponse }
  | { status: 'rate_limited' }
  | { status: 'network' }
  | { status: 'error' };

/**
 * Run a guided in-ride POI search (`POST /trips/{id}/nearby-pois`, ADR-048).
 *
 * `radiusMeters` is left null on the first search so the backend applies its
 * per-category default; the "widen" affordance replays with a doubled radius.
 * Classifies the transient 429 apart from other errors so the panel surfaces a
 * dedicated rate-limit message and does not retry aggressively.
 */
export async function searchNearbyPois(
  tripId: string,
  params: {
    category: InRidePoiCategory;
    position: { lat: number; lon: number };
    radiusMeters?: number | null;
    stageDay?: number | null;
  },
): Promise<NearbyPoiSearchResult> {
  let response: Response;
  let data: unknown;
  try {
    const result = await api.POST('/trips/{id}/nearby-pois', {
      params: { path: { id: tripId } },
      headers: {
        Accept: 'application/ld+json',
        'Content-Type': 'application/ld+json',
      },
      body: {
        category: params.category,
        position: params.position,
        radiusMeters: params.radiusMeters ?? null,
        stageDay: params.stageDay ?? null,
      },
    });
    response = result.response;
    data = result.data;
  } catch {
    // Network failure — the openapi-fetch promise rejects (offline / DNS).
    return { status: 'network' };
  }

  if (response.ok && data) {
    return { status: 'ok', data: data as NearbyPoiSearchResponse };
  }
  if (response.status === 429) {
    return { status: 'rate_limited' };
  }
  return { status: 'error' };
}
