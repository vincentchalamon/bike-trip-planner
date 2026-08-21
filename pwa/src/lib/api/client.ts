import createClient, { type Middleware } from "openapi-fetch";
import { z } from "zod";
import type { components, operations, paths } from "@btp/core/schema";
import { API_URL } from "@/lib/constants";
import { useAuthStore } from "@/store/auth-store";

function getBrowserLocale(): string {
  if (typeof navigator !== "undefined") {
    return navigator.language;
  }
  return "fr";
}

/**
 * Header name used to propagate the correlation ID end-to-end (Caddy →
 * Symfony → workers → Mercure → PWA). See issue #485.
 */
export const REQUEST_ID_HEADER = "X-Request-Id";

/**
 * Last known correlation ID observed from a server response. The value is
 * resent on every subsequent request as `X-Request-Id` so all calls in the
 * same user session share a trace identifier, and surfaced to UI components
 * (e.g. the Sonner toast `Request ID: <uuid>` description / `toast-<uuid>`
 * `<li id>`) for copy-paste diagnostics.
 *
 * Stored in module scope (not the auth store) so it survives auth state
 * resets and remains importable from non-React code (`parseApiError`
 * callers, error boundaries, …).
 */
let lastRequestId: string | null = null;

export function getLastRequestId(): string | null {
  return lastRequestId;
}

/**
 * Extracts the correlation ID from a `Response` (case-insensitive) and pins
 * it as the last-seen value when present. Exposed for callers that talk to
 * the API outside of the `openapi-fetch` middleware (e.g. `apiFetch`).
 */
export function rememberRequestId(response: Response): string | null {
  const value = response.headers.get(REQUEST_ID_HEADER);
  if (value && value.trim() !== "") {
    lastRequestId = value;
    return value;
  }
  return null;
}

/**
 * Get the current Authorization header value from the auth store.
 * Returns undefined when no access token is available.
 */
function getAuthHeader(): string | undefined {
  const { accessToken } = useAuthStore.getState();
  return accessToken ? `Bearer ${accessToken}` : undefined;
}

/**
 * Lightweight wrapper around `fetch` that injects the Accept-Language header
 * and the Authorization bearer token (when available).
 *
 * Used for non-OpenAPI calls (GPX upload, accommodation scan, etc.) where
 * the openapi-fetch middleware pipeline is bypassed.
 */
export async function apiFetch(
  input: string,
  init?: RequestInit,
): Promise<Response> {
  // Wait for the initial auth check to settle so the call carries the resolved
  // token instead of firing before the bootstrap silent-refresh — same gating as
  // the openapi-fetch middleware (recette #649 #8). Every apiFetch endpoint is
  // authenticated; the anonymous shared view uses a raw fetch, not apiFetch.
  await useAuthStore.getState().ensureResolved();
  const authHeader = getAuthHeader();
  const baseHeaders: Record<string, string> = {
    "Accept-Language": getBrowserLocale(),
  };
  if (lastRequestId !== null) {
    baseHeaders[REQUEST_ID_HEADER] = lastRequestId;
  }
  if (authHeader) {
    baseHeaders.Authorization = authHeader;
  }
  const res = await fetch(input, {
    ...init,
    headers: {
      ...baseHeaders,
      ...init?.headers,
    },
  });
  rememberRequestId(res);

  // On 401, attempt a silent refresh and retry once
  if (res.status === 401) {
    const refreshed = await useAuthStore.getState().silentRefresh();
    if (refreshed) {
      const newAuthHeader = getAuthHeader();
      const retryHeaders: Record<string, string> = {
        "Accept-Language": getBrowserLocale(),
      };
      if (lastRequestId !== null) {
        retryHeaders[REQUEST_ID_HEADER] = lastRequestId;
      }
      if (newAuthHeader) {
        retryHeaders.Authorization = newAuthHeader;
      }
      const retry = await fetch(input, {
        ...init,
        headers: {
          ...retryHeaders,
          ...init?.headers,
        },
      });
      rememberRequestId(retry);
      return retry;
    }
    // Refresh failed — redirect to login
    if (typeof window !== "undefined") {
      window.location.href = "/login";
    }
  }

  return res;
}

/**
 * openapi-fetch middleware that injects the JWT access token on every request
 * and handles 401 responses with a silent refresh + retry strategy.
 *
 * Flow on 401:
 * 1. Call `silentRefresh()` to rotate the refresh_token cookie and get a new JWT
 * 2. If refresh succeeds → retry the original request with the new token
 * 3. If refresh fails → redirect to `/login`
 */
// Cache request bodies (as text) before fetch consumes them, so a 401 retry can
// resend them. A string body is single-shot-safe and needs no `duplex` option,
// unlike the ReadableStream a cloned Request exposes (recette #649 #8).
const requestBodyCache = new WeakMap<Request, string | null>();

/**
 * openapi-fetch middleware that propagates the correlation ID:
 * - injects the last-seen `X-Request-Id` on outgoing requests so all calls
 *   in the same user session share a trace identifier;
 * - captures the response header value (whether Caddy forwarded ours or
 *   minted a fresh one) and pins it for the next call + UI consumers.
 *
 * Mounted before {@link authMiddleware} so retries triggered by the auth
 * middleware reuse the captured request ID rather than overwriting it.
 */
const requestIdMiddleware: Middleware = {
  onRequest({ request }) {
    if (lastRequestId && !request.headers.has(REQUEST_ID_HEADER)) {
      request.headers.set(REQUEST_ID_HEADER, lastRequestId);
    }
    return request;
  },
  onResponse({ response }) {
    rememberRequestId(response);
    return response;
  },
};

const authMiddleware: Middleware = {
  async onRequest({ request }) {
    // Read the body to TEXT before fetch consumes it, so the 401 retry in
    // onResponse can resend it. Caching the cloned ReadableStream instead made
    // the retry POST go out with an EMPTY body (a stream body is single-use and
    // needs `duplex: "half"`), so the API saw no payload → 400 "Syntax error".
    // This broke `?link=` trip creation, whose POST fires before the access
    // token is ready (401 → retry) (recette #649 #8).
    requestBodyCache.set(
      request,
      request.body ? await request.clone().text() : null,
    );
    // Wait for the initial auth check to settle so we attach the resolved token
    // rather than firing before the app's bootstrap silent-refresh has run — the
    // primary cause of the `?link=` 401→retry round-trip (recette #649 #8). The
    // refresh is deduped, so this triggers at most one per session; once settled
    // it is a no-op. The 401 retry below remains the safety net for a token that
    // expires mid-session.
    await useAuthStore.getState().ensureResolved();
    const authValue = getAuthHeader();
    if (authValue) {
      request.headers.set("Authorization", authValue);
    }
    return request;
  },

  async onResponse({ request, response }) {
    if (response.status !== 401) {
      return response;
    }

    const refreshed = await useAuthStore.getState().silentRefresh();
    if (!refreshed) {
      if (typeof window !== "undefined") {
        window.location.href = "/login";
      }
      return response;
    }

    // Retry with the new token — rebuild from scratch to avoid bodyUsed TypeError
    const newAuthValue = getAuthHeader();
    const headers = new Headers(request.headers);
    if (newAuthValue) {
      headers.set("Authorization", newAuthValue);
    }
    return fetch(request.url, {
      method: request.method,
      headers,
      body: requestBodyCache.get(request),
      credentials: request.credentials,
      cache: request.cache,
      redirect: request.redirect,
      referrer: request.referrer,
      integrity: request.integrity,
      signal: request.signal,
    });
  },
};

export const apiClient = createClient<paths>({
  baseUrl: process.env.NEXT_PUBLIC_API_URL ?? "",
  headers: {
    "Content-Type": "application/ld+json",
    Accept: "application/ld+json",
    "Accept-Language": getBrowserLocale(),
  },
});

apiClient.use(requestIdMiddleware);
apiClient.use(authMiddleware);

export interface ApiError {
  type: "validation" | "bad_request" | "not_found" | "network";
  message: string;
  violations?: { propertyPath: string; message: string }[];
}

interface ViolationBody {
  violations?: { propertyPath: string; message: string }[];
}

interface DetailBody {
  detail?: string;
}

function hasViolations(body: unknown): body is ViolationBody {
  return (
    body !== null &&
    typeof body === "object" &&
    "violations" in body &&
    Array.isArray((body as ViolationBody).violations)
  );
}

function hasDetail(body: unknown): body is DetailBody {
  return body !== null && typeof body === "object" && "detail" in body;
}

/**
 * Localized fallback message key (under the `errors` namespace) for each error
 * `type`. Used by {@link localizedApiErrorMessage} when the API did not provide
 * a human-readable message of its own (e.g. a 404, or a 422 with empty
 * violations). API-provided messages (400 `detail`, 422 `violations`) — which
 * may already be localized server-side — are preserved as-is.
 */
const API_ERROR_FALLBACK_KEY: Record<ApiError["type"], string> = {
  validation: "errors.validationError",
  bad_request: "errors.badRequest",
  not_found: "errors.notFound",
  network: "errors.unexpectedError",
};

export function parseApiError(status: number, body: unknown): ApiError {
  if (status === 422 && hasViolations(body)) {
    const violations = body.violations ?? [];
    return {
      type: "validation",
      message: violations.map((v) => v.message).join(", "),
      violations,
    };
  }

  if (status === 400) {
    const detail = hasDetail(body) ? body.detail : undefined;
    return {
      type: "bad_request",
      message: detail ?? "",
    };
  }

  if (status === 404) {
    return {
      type: "not_found",
      message: "",
    };
  }

  return {
    type: "network",
    message: "",
  };
}

/**
 * Resolve the message to display for an {@link ApiError}: the API-provided
 * message when present (e.g. a 422 violation or a 400 `detail`, possibly
 * already translated server-side), otherwise the localized generic fallback
 * for the error `type`.
 *
 * @param t a next-intl translator scoped to the root namespace.
 */
export function localizedApiErrorMessage(
  error: ApiError,
  t: (key: string) => string,
): string {
  return error.message || t(API_ERROR_FALLBACK_KEY[error.type]);
}

export function isNetworkError(error: unknown): error is TypeError {
  return error instanceof TypeError && error.message === "Failed to fetch";
}

/**
 * Trigger a route segment recalculation with a POI waypoint insertion.
 * Returns `true` on success, `false` when the trip is not found or the request fails.
 */
export async function addPoiWaypointToRoute(
  tripId: string,
  stageIndex: number,
  waypointLat: number,
  waypointLon: number,
): Promise<boolean> {
  const { response } = await apiClient.POST(
    "/trips/{tripId}/stages/{index}/poi-waypoint",
    {
      params: { path: { tripId, index: String(stageIndex) } },
      body: { waypointLat, waypointLon },
    },
  );
  return response.ok;
}

/**
 * Add a manually-entered (hors-app) accommodation to a stage. The backend
 * geocodes the address, makes the accommodation the stage's selected one and
 * moves the stage boundary to it (same downstream as selecting a scanned entry).
 * Returns the HTTP status so the caller can distinguish a 422 (address not
 * geocodable) from a generic failure.
 */
export async function addManualAccommodation(
  tripId: string,
  stageIndex: number,
  data: {
    name: string;
    address: string;
    priceTotal: number | null;
    url: string | null;
  },
): Promise<{ ok: boolean; status: number }> {
  const { response } = await apiClient.POST(
    "/trips/{tripId}/stages/{index}/accommodations/manual",
    {
      params: { path: { tripId, index: String(stageIndex) } },
      body: {
        name: data.name,
        address: data.address,
        priceTotal: data.priceTotal,
        url: data.url,
      },
    },
  );
  return { ok: response.ok, status: response.status };
}

/**
 * Trigger an accommodation re-scan with a custom radius.
 * When `stageIndex` is provided, only that stage's endpoint is scanned.
 * Returns `true` on success, `false` when the trip is not found or the request fails.
 */
export async function scanAccommodations(
  tripId: string,
  radiusKm: number,
  stageIndex?: number,
): Promise<boolean> {
  const res = await apiFetch(
    `${API_URL}/trips/${encodeURIComponent(tripId)}/accommodations/scan`,
    {
      method: "POST",
      headers: { "Content-Type": "application/ld+json" },
      body: JSON.stringify({
        radiusKm,
        ...(stageIndex !== undefined && { stageIndex }),
      }),
    },
  );
  return res.ok;
}

/**
 * Response type for GPX upload, derived from the OpenAPI specification.
 * Single source of truth: backend DTO -> OpenAPI spec -> typegen -> this type.
 */
export type GpxUploadResponse =
  operations["gpxUpload"]["responses"]["202"]["content"]["application/json"];

/**
 * Upload a GPX file to create a new trip.
 * The backend parses the GPX synchronously and dispatches async computations.
 */
export async function uploadGpxFile(
  file: File,
  options?: {
    startDate?: string | null;
    fatigueFactor?: number;
    elevationPenalty?: number;
    maxDistancePerDay?: number;
    averageSpeed?: number;
    ebikeMode?: boolean;
    enabledAccommodationTypes?: string[];
  },
): Promise<{
  data: GpxUploadResponse | null;
  error: string | null;
  response: Response | null;
}> {
  const formData = new FormData();
  formData.append("gpxFile", file);

  if (options?.startDate) {
    formData.append("startDate", options.startDate);
  }
  if (options?.fatigueFactor !== undefined) {
    formData.append("fatigueFactor", String(options.fatigueFactor));
  }
  if (options?.elevationPenalty !== undefined) {
    formData.append("elevationPenalty", String(options.elevationPenalty));
  }
  if (options?.maxDistancePerDay !== undefined) {
    formData.append("maxDistancePerDay", String(options.maxDistancePerDay));
  }
  if (options?.averageSpeed !== undefined) {
    formData.append("averageSpeed", String(options.averageSpeed));
  }
  if (options?.ebikeMode !== undefined) {
    formData.append("ebikeMode", String(options.ebikeMode));
  }
  if (options?.enabledAccommodationTypes !== undefined) {
    options.enabledAccommodationTypes.forEach((type) => {
      formData.append("enabledAccommodationTypes[]", type);
    });
  }

  const res = await apiFetch(`${API_URL}/trips/gpx-upload`, {
    method: "POST",
    body: formData,
  });

  if (!res.ok) {
    const body = (await res.json().catch(() => null)) as {
      error?: string;
    } | null;
    return { data: null, error: body?.error ?? "Upload failed", response: res };
  }

  const data = (await res.json()) as GpxUploadResponse;
  return { data, error: null, response: res };
}

/**
 * Apply a batch of pending modifications in a single recompute pass.
 *
 * Sends `POST /trips/{id}/recompute` with all queued modifications so the backend
 * dispatches only the minimal set of handlers required — avoiding N sequential
 * recomputations for N changes.
 *
 * Returns `true` on HTTP 2xx; `false` otherwise.
 */
export async function applyBatchRecompute(
  tripId: string,
  modifications: components["schemas"]["TripModification"][],
): Promise<boolean> {
  const { response } = await apiClient.POST("/trips/{id}/recompute", {
    params: { path: { id: tripId } },
    body: { modifications },
  });
  return response.ok;
}

/**
 * Trigger the full Phase 2 enrichment pipeline (POIs, weather, terrain, …)
 * for a trip whose stages have been pre-computed during Phase 1.
 *
 * Returns `true` on HTTP 2xx; `false` otherwise.
 *
 * Note: until the OpenAPI schema is regenerated (after #322 lands on main),
 * the `/trips/{id}/analyze` route is not yet exposed via `apiClient.POST`,
 * so this function talks to the server through the lower-level {@link apiFetch}.
 * Once the typegen catches up, this can be swapped for
 * `apiClient.POST("/trips/{id}/analyze", { params: { path: { id } } })`.
 */
export async function launchTripAnalysis(tripId: string): Promise<boolean> {
  const res = await apiFetch(`${API_URL}/trips/${tripId}/analyze`, {
    method: "POST",
    headers: {
      "Content-Type": "application/ld+json",
      Accept: "application/ld+json",
    },
  });
  return res.ok;
}

/**
 * POI payload for a mid-ride suggestion. Inferred from {@link poiSuggestionSchema}
 * below — the single runtime source of truth for the POI wire shape.
 */
export type PoiSuggestionDto = z.infer<typeof poiSuggestionSchema>;

/**
 * Single source of truth for the POI payload Zod shape and its inferred
 * {@link PoiSuggestionDto} type. Kept for the guided in-ride search (#935).
 */
// `deeplink` is rendered straight into `<a href={poi.deeplink}>` in PoiCard.
// React 19 still emits a runtime warning for `javascript:`/`data:` URLs
// instead of stripping them, so we treat the Zod schema as a defence-in-depth
// gate: only http(s) deeplinks pass validation. The backend's DeeplinkBuilder
// already only produces https URLs, so this exclusively guards against a
// tampered or corrupt persisted record reaching the DOM.
const safeUrlSchema = z
  .string()
  .url()
  .refine((u) => /^https?:\/\//i.test(u), {
    message: "deeplink must be an http(s) URL",
  });

const poiSuggestionSchema = z.object({
  name: z.string(),
  category: z.string(),
  lat: z.number(),
  lon: z.number(),
  distance_m: z.number(),
  // null = no remaining route was available to measure the detour against.
  detour_m: z.number().nullable(),
  opening_hours_today: z.string().nullable(),
  closes_at: z.string().nullable(),
  phone: z.string().nullable(),
  deeplink: safeUrlSchema,
  // Typed backend warning code (closes_soon | far_from_route | hours_unverified).
  warning: z.string().nullable(),
  // Minutes left before closing when `warning` is `closes_soon`.
  warning_minutes: z.number().nullable(),
});

/**
 * The eight guided in-ride intent categories (#935). Derived from the generated
 * OpenAPI schema so the union stays in lockstep with `App\InRide\InRidePoiCategory`.
 */
export type InRidePoiCategory =
  components["schemas"]["PoiSuggestionDto.jsonld"]["category"];

/**
 * Runtime shape of the guided in-ride search response
 * (`POST /trips/{id}/nearby-pois`, #934). Reuses {@link poiSuggestionSchema} for
 * the POI array; every scalar carries a default so a partial body never throws.
 */
const nearbyPoiSearchResponseSchema = z.object({
  tripId: z.string().default(""),
  category: z.string(),
  radiusMeters: z.number(),
  totalFound: z.number().default(0),
  capReached: z.boolean().default(false),
  outOfCoverage: z.boolean().default(false),
  pois: z.array(poiSuggestionSchema).default([]),
});

export type NearbyPoiSearchResponse = z.infer<
  typeof nearbyPoiSearchResponseSchema
>;

/**
 * Outcome of {@link searchNearbyPois}. Discriminated so the caller maps the
 * backend's failure modes to localized messages without re-deriving them from a
 * raw status code:
 *
 * - `ok`           — the search result (category, radius, POIs, coverage flags).
 * - `rate_limited` — 429: per-user in-ride search rate limit reached (transient).
 * - `network`      — the request never reached the backend (offline / DNS).
 * - `error`        — any other failure (4xx/5xx, bad shape).
 */
export type NearbyPoiSearchResult =
  | { status: "ok"; data: NearbyPoiSearchResponse }
  | { status: "rate_limited" }
  | { status: "network" }
  | { status: "error" };

/**
 * Run a guided in-ride POI search (`POST /trips/{id}/nearby-pois`, #934/#935).
 *
 * `radiusMeters` is left null on the first search so the backend applies its
 * per-category default; the "widen" affordance replays with a doubled radius.
 * Uses the typed {@link apiClient} (the route is fully described by the
 * generated schema) and classifies the transient 429 apart from other errors.
 */
export async function searchNearbyPois(
  tripId: string,
  params: {
    category: InRidePoiCategory;
    position: { lat: number; lon: number };
    radiusMeters?: number | null;
    stageDay?: number | null;
  },
  signal?: AbortSignal,
): Promise<NearbyPoiSearchResult> {
  let response: Response;
  let data: unknown;
  try {
    const result = await apiClient.POST("/trips/{id}/nearby-pois", {
      params: { path: { id: tripId } },
      body: {
        category: params.category,
        position: params.position,
        radiusMeters: params.radiusMeters ?? null,
        stageDay: params.stageDay ?? null,
      },
      ...(signal ? { signal } : {}),
    });
    response = result.response;
    data = result.data;
  } catch {
    // Network failure / aborted request — the openapi-fetch promise rejects.
    return { status: "network" };
  }

  if (response.ok && data) {
    const parsed = nearbyPoiSearchResponseSchema.safeParse(data);
    if (!parsed.success) return { status: "error" };
    return { status: "ok", data: parsed.data };
  }

  if (response.status === 429) return { status: "rate_limited" };
  return { status: "error" };
}

/**
 * Duplicate an existing trip (deep-clone with all stages and settings).
 * Returns the new trip id on success, null on failure.
 */
export async function duplicateTrip(
  tripId: string,
): Promise<{ id: string; computationStatus: Record<string, string> } | null> {
  const { data, error } = await apiClient.POST("/trips/{id}/duplicate", {
    params: { path: { id: tripId } },
  });
  if (error || !data?.id) return null;
  return {
    id: data.id,
    computationStatus: (data.computationStatus as Record<string, string>) ?? {},
  };
}

/**
 * Permanently delete a trip and all its stages (`DELETE /trips/{id}`).
 * Used both by the trips list and by the in-trip configuration drawer (#649).
 *
 * @returns true when the backend confirms deletion, false otherwise.
 */
export async function deleteTrip(tripId: string): Promise<boolean> {
  const { response } = await apiClient.DELETE("/trips/{id}", {
    params: { path: { id: tripId } },
  });
  // A 204 has no body, so `error` stays undefined even on 5xx empty responses;
  // rely on the HTTP status to tell success from failure.
  return response.ok;
}

/**
 * Download the full trip as a single GPX file containing all stages and trigger
 * a browser save dialog.
 * @throws {Error} When the server responds with a non-2xx status.
 */
function triggerBlobDownload(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

/** Sanitize a trip title into a filesystem-safe base name. */
function sanitizeFileBase(title: string): string {
  return title.trim().replace(/[^a-z0-9\-_]/gi, "-") || "trip";
}

/** e.g. "Entre Sensée et Escaut" + day 1 → "Entre-Sens-e-et-Escaut-stage-1.gpx". */
export function stageFileName(
  tripTitle: string,
  dayNumber: number,
  format: "gpx" | "fit",
): string {
  return `${sanitizeFileBase(tripTitle)}-stage-${dayNumber}.${format}`;
}

export async function downloadTripFile(
  tripId: string,
  tripTitle: string,
  format: "gpx" | "fit",
): Promise<void> {
  const res = await apiFetch(`${API_URL}/trips/${tripId}.${format}`);
  if (!res.ok) throw new Error(`Download failed with status ${res.status}`);
  const blob = await res.blob();
  triggerBlobDownload(blob, `${sanitizeFileBase(tripTitle)}.${format}`);
}

export async function downloadStageFile(
  tripId: string,
  stageIndex: number,
  format: "gpx" | "fit",
  dayNumber: number,
  tripTitle: string,
): Promise<void> {
  const res = await apiFetch(
    `${API_URL}/trips/${tripId}/stages/${stageIndex}/export.${format}`,
  );
  if (!res.ok) throw new Error(`Download failed with status ${res.status}`);
  const blob = await res.blob();
  triggerBlobDownload(blob, stageFileName(tripTitle, dayNumber, format));
}

/**
 * GDPR right to portability (#549, #383): download the authenticated user's
 * full data archive (`GET /users/me/export`) as a JSON file and trigger a
 * browser save dialog.
 *
 * @throws {Error} When the server responds with a non-2xx status.
 */
export async function downloadAccountExport(): Promise<void> {
  const res = await apiFetch(`${API_URL}/users/me/export`, {
    headers: { Accept: "application/ld+json" },
  });
  if (!res.ok) throw new Error(`Export failed with status ${res.status}`);
  const blob = await res.blob();
  const today = new Date().toISOString().slice(0, 10);
  triggerBlobDownload(blob, `bike-trip-planner-export-${today}.json`);
}

/**
 * GDPR right to erasure (#549, #383): permanently delete the authenticated
 * user's account (`DELETE /users/me`). The backend anonymises the account,
 * purges trips and preferences, and revokes refresh tokens.
 *
 * @returns true on HTTP 204, false otherwise.
 */
export async function deleteAccount(): Promise<boolean> {
  const { response } = await apiClient.DELETE("/users/me");
  return response.ok;
}

/**
 * Request an email change (#777): asks the backend to send a confirmation link
 * to {newEmail} (`POST /users/me/email-change`). The current email is unchanged
 * until the link is verified.
 *
 * @returns `{ ok: true }` on HTTP 202, or `{ ok: false, error }` with the parsed
 * {@link ApiError} (e.g. 422 same-email / already-used / invalid format).
 */
export async function requestEmailChange(
  newEmail: string,
): Promise<{ ok: true } | { ok: false; error: ApiError }> {
  const res = await apiFetch(`${API_URL}/users/me/email-change`, {
    method: "POST",
    headers: {
      "Content-Type": "application/ld+json",
      Accept: "application/ld+json",
    },
    body: JSON.stringify({ newEmail }),
  });
  if (res.ok) return { ok: true };
  const body = (await res.json().catch(() => null)) as unknown;
  return { ok: false, error: parseApiError(res.status, body) };
}

/**
 * Verify an email-change token (#777): consumes the single-use {token} from the
 * confirmation link (`POST /users/me/email-change/verify`) and commits the new
 * address server-side.
 *
 * @returns the confirmed new email on success, or null on any failure (invalid /
 * expired / already consumed token, or the target address taken since request).
 */
export async function verifyEmailChange(token: string): Promise<string | null> {
  const res = await apiFetch(`${API_URL}/users/me/email-change/verify`, {
    method: "POST",
    headers: {
      "Content-Type": "application/ld+json",
      Accept: "application/ld+json",
    },
    body: JSON.stringify({ token }),
  });
  if (!res.ok) return null;
  const body = (await res.json().catch(() => null)) as {
    email?: string;
  } | null;
  return body?.email ?? null;
}

/**
 * Build the frontend share URL from a short code.
 */
export function buildShareUrl(shortCode: string): string {
  const origin =
    typeof window !== "undefined"
      ? window.location.origin
      : "https://localhost";
  return `${origin}/s/${encodeURIComponent(shortCode)}`;
}

/**
 * Get the active share link for a trip.
 * @returns The share metadata (id, token), or null if none exists.
 */
export type TripShareResponse = components["schemas"]["TripShare.jsonld"];

export async function getTripShare(
  tripId: string,
): Promise<TripShareResponse | null> {
  const res = await apiFetch(
    `${API_URL}/trips/${encodeURIComponent(tripId)}/share`,
    { headers: { Accept: "application/ld+json" } },
  );
  if (!res.ok) return null;
  return res.json() as Promise<TripShareResponse>;
}

/**
 * Create a read-only share link for a trip.
 * @returns The share metadata (id, token), or null on failure.
 */
export async function createTripShare(
  tripId: string,
): Promise<TripShareResponse | null> {
  const res = await apiFetch(
    `${API_URL}/trips/${encodeURIComponent(tripId)}/share`,
    {
      method: "POST",
      headers: {
        "Content-Type": "application/ld+json",
        Accept: "application/ld+json",
      },
      body: JSON.stringify({}),
    },
  );
  if (!res.ok) return null;
  return res.json() as Promise<TripShareResponse>;
}

/**
 * Revoke the active share link for a trip (soft delete).
 * @returns true on success, false on failure.
 */
export async function revokeTripShare(tripId: string): Promise<boolean> {
  const res = await apiFetch(
    `${API_URL}/trips/${encodeURIComponent(tripId)}/share`,
    { method: "DELETE" },
  );
  return res.ok;
}

/**
 * Fetch a shared trip via short code (anonymous, no auth required).
 */
export type SharedTripDetail =
  components["schemas"]["TripShare.TripDetail.jsonld"];

export async function fetchSharedTrip(
  shortCode: string,
): Promise<SharedTripDetail | null> {
  const res = await fetch(`${API_URL}/s/${encodeURIComponent(shortCode)}`, {
    headers: { Accept: "application/ld+json" },
  });
  if (!res.ok) return null;
  return res.json() as Promise<SharedTripDetail>;
}

// All-stages geometry, split off the trip summary (ADR-057), fetched on demand.
export type TripRoute = components["schemas"]["TripRoute.jsonld"];

export async function fetchTripRoute(id: string): Promise<TripRoute | null> {
  const { data, error } = await apiClient.GET("/trips/{id}/route", {
    params: { path: { id } },
  });
  if (error) return null;
  return data ?? null;
}

// Public counterpart for the anonymous shared view (no auth token).
export async function fetchSharedTripRoute(
  shortCode: string,
): Promise<TripRoute | null> {
  const res = await fetch(
    `${API_URL}/s/${encodeURIComponent(shortCode)}/route`,
    { headers: { Accept: "application/ld+json" } },
  );
  if (!res.ok) return null;
  return res.json() as Promise<TripRoute>;
}

/**
 * Download a shared trip as GPX or FIT via short code (anonymous).
 */
export async function downloadSharedTripFile(
  shortCode: string,
  tripTitle: string,
  format: "gpx" | "fit",
): Promise<void> {
  const res = await fetch(
    `${API_URL}/s/${encodeURIComponent(shortCode)}.${format}`,
  );
  if (!res.ok) throw new Error("Download failed");
  const blob = await res.blob();
  triggerBlobDownload(blob, `${sanitizeFileBase(tripTitle)}.${format}`);
}

/**
 * Download shared stage as GPX or FIT via short code (anonymous).
 */
export async function downloadSharedStageFile(
  shortCode: string,
  stageIndex: number,
  format: "gpx" | "fit",
  dayNumber: number,
  tripTitle: string,
): Promise<void> {
  const res = await fetch(
    `${API_URL}/s/${encodeURIComponent(shortCode)}/stages/${stageIndex}.${format}`,
  );
  if (!res.ok) throw new Error("Download failed");
  const blob = await res.blob();
  triggerBlobDownload(blob, stageFileName(tripTitle, dayNumber, format));
}
