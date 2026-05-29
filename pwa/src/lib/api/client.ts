import createClient, { type Middleware } from "openapi-fetch";
import { z } from "zod";
import type { components, operations, paths } from "./schema";
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
 * Used for non-OpenAPI calls (GPX upload, accommodation scrape, etc.) where
 * the openapi-fetch middleware pipeline is bypassed.
 */
export async function apiFetch(
  input: string,
  init?: RequestInit,
): Promise<Response> {
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
// Cache request bodies before they are consumed by fetch, so the retry can reuse them.
const requestBodyCache = new WeakMap<Request, BodyInit | null>();

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
  onRequest({ request }) {
    // Clone body before it is consumed so the retry in onResponse can reuse it.
    // request.body is a ReadableStream — once fetch() consumes it, it's locked.
    // request.clone() creates an independent copy whose stream remains unconsumed.
    requestBodyCache.set(request, request.body ? request.clone().body : null);
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

export function parseApiError(status: number, body: unknown): ApiError {
  if (status === 422 && hasViolations(body)) {
    const violations = body.violations ?? [];
    return {
      type: "validation",
      message:
        violations.map((v) => v.message).join(", ") || "Validation error",
      violations,
    };
  }

  if (status === 400) {
    const detail = hasDetail(body) ? body.detail : undefined;
    return {
      type: "bad_request",
      message: detail ?? "Bad request",
    };
  }

  if (status === 404) {
    return {
      type: "not_found",
      message: "Resource not found",
    };
  }

  return {
    type: "network",
    message: "An unexpected error occurred",
  };
}

export function isNetworkError(error: unknown): error is TypeError {
  return error instanceof TypeError && error.message === "Failed to fetch";
}

export interface ScrapedData {
  name: string | null;
  type: string | null;
  priceMin: number | null;
  priceMax: number | null;
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
 * Scrape accommodation metadata from the given URL via the backend proxy.
 * @returns Scraped data, or `null` when the URL is unsupported or the request fails.
 */
export async function scrapeAccommodation(
  url: string,
): Promise<ScrapedData | null> {
  const res = await apiFetch(`${API_URL}/accommodations/scrape`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ url }),
  });
  if (!res.ok) return null;
  return res.json() as Promise<ScrapedData>;
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
 * Body of `POST /trips/{id}/chat`. Mirrors `App\ApiResource\TripChatRequest`
 * on the backend; declared locally until `make typegen` ingests the schema
 * change introduced by issue #309.
 */
export interface TripChatRequestBody {
  message: string;
  context?: {
    currentStage: number | null;
  } | null;
  /**
   * Optional rider GPS position. When set, the backend switches the assistant
   * to in-ride POI search mode (see `App\InRide\InRideAssistant`).
   *
   * Sourced from the generated OpenAPI schema so any future field addition on
   * the backend `GeoPosition` model (e.g. accuracy) is picked up here without
   * a manual edit.
   */
  position?: components["schemas"]["GeoPosition"] | null;
}

/**
 * POI payload returned in-ride. Sourced directly from the generated OpenAPI
 * schema (`pois` items on `Trip.TripChatResponse.jsonld`) so the wire contract
 * stays in lockstep with `App\InRide\PoiSuggestion::toArray()`.
 */
export type PoiSuggestionDto = NonNullable<
  NonNullable<
    components["schemas"]["Trip.TripChatResponse.jsonld"]["pois"]
  >[number]
>;

/**
 * Response of `POST /trips/{id}/chat`. Mirrors `App\ApiResource\TripChatResponse`
 * on the backend (`tripId`, `action`, `params`, `response`, `dispatched`,
 * `impactedStageNumbers`, `requiresFullAnalysis`).
 */
export interface TripChatResponseBody {
  tripId: string;
  action: string;
  params: Record<string, unknown>;
  response: string;
  dispatched: boolean;
  /**
   * 1-indexed day numbers whose recomputation was dispatched (chat-driven
   * inline edits). Empty for `info` and `change_route` actions.
   */
  impactedStageNumbers?: number[];
  /**
   * True when the action requires a full trip re-analysis (Acte 2). The
   * frontend should bounce the rider back to the analysis screen and offer
   * a "Relancer l'analyse" button.
   */
  requiresFullAnalysis?: boolean;
  /**
   * Top POI suggestions returned in-ride. Only populated when the request
   * carried a GPS `position`. Empty / undefined in planning mode.
   */
  pois?: PoiSuggestionDto[] | null;
}

/**
 * Send a natural-language instruction to the LLaMA 3B dialogue assistant.
 *
 * Until the OpenAPI schema is regenerated (after #309 lands on main), the
 * `/trips/{id}/chat` route is not yet exposed via `apiClient.POST`, so this
 * function talks to the server through {@link apiFetch}. Once the typegen
 * catches up, this can be swapped for a typed call.
 */
export async function sendTripChat(
  tripId: string,
  body: TripChatRequestBody,
  signal?: AbortSignal,
): Promise<{
  data: TripChatResponseBody | null;
  error: string | null;
  status: number;
}> {
  const res = await apiFetch(`${API_URL}/trips/${tripId}/chat`, {
    method: "POST",
    headers: {
      "Content-Type": "application/ld+json",
      Accept: "application/ld+json",
    },
    body: JSON.stringify(body),
    signal,
  });

  if (!res.ok) {
    return { data: null, error: `HTTP ${res.status}`, status: res.status };
  }

  const raw: unknown = await res.json();
  const parsed = tripChatResponseSchema.safeParse(raw);
  if (!parsed.success) {
    return {
      data: null,
      error: "Invalid response shape",
      status: res.status,
    };
  }

  return { data: parsed.data, error: null, status: res.status };
}

/**
 * One persisted chat turn returned by `GET /trips/{id}/chat-history`.
 *
 * Mirrors `App\ApiResource\TripChatMessageResource`. Messages are returned
 * most-recent first; consumers reverse the array for chronological rendering.
 *
 * We intentionally keep this interface manual rather than swapping it for
 * `components["schemas"]["TripChatMessage.jsonld"]`: API Platform marks every
 * field on the generated schema as optional, which weakens the consumer
 * contract (every property reads as `T | undefined`). The Zod schema below
 * still enforces the strict shape at runtime, so any backend drift would
 * surface as a parse failure in `fetchTripChatHistory`.
 */
export interface TripChatMessageHistoryEntry {
  id: string;
  tripId: string;
  role: "user" | "assistant";
  content: string;
  action: string | null;
  geoLat: number | null;
  geoLon: number | null;
  pois: PoiSuggestionDto[];
  createdAt: string;
}

/**
 * Single source of truth for the POI payload Zod shape — referenced by both
 * the chat-history entry schema and the trip-chat response schema below so a
 * future field addition or rename in `App\InRide\PoiSuggestion::toArray()`
 * only has to be reflected here.
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
  detour_m: z.number(),
  opening_hours_today: z.string().nullable(),
  closes_at: z.string().nullable(),
  phone: z.string().nullable(),
  deeplink: safeUrlSchema,
  warning: z.string().nullable(),
});

const chatHistoryEntrySchema = z.object({
  id: z.string(),
  tripId: z.string(),
  role: z.enum(["user", "assistant"]),
  content: z.string(),
  action: z.string().nullable(),
  geoLat: z.number().nullable(),
  geoLon: z.number().nullable(),
  pois: z.array(poiSuggestionSchema).catch([]).default([]),
  createdAt: z.string(),
});

/**
 * Fetch the persisted chat history for a trip. Returns chronologically-ordered
 * turns (oldest first) ready to feed into the chat panel store.
 *
 * The backend serves a JSON-LD `member` collection sorted most-recent first;
 * this helper reverses it so callers can `push` each message in order.
 */
export async function fetchTripChatHistory(
  tripId: string,
  options: { limit?: number; signal?: AbortSignal } = {},
): Promise<TripChatMessageHistoryEntry[]> {
  const params = new URLSearchParams();
  if (options.limit !== undefined) params.set("limit", String(options.limit));
  const query = params.toString();
  const url = `${API_URL}/trips/${tripId}/chat-history${query ? `?${query}` : ""}`;

  const res = await apiFetch(url, {
    method: "GET",
    headers: { Accept: "application/ld+json" },
    signal: options.signal,
  });

  if (!res.ok) {
    return [];
  }

  const raw: unknown = await res.json();
  const envelope = z
    .object({ member: z.array(chatHistoryEntrySchema) })
    .safeParse(raw);
  if (!envelope.success) {
    return [];
  }
  return [...envelope.data.member].reverse();
}

const tripChatResponseSchema = z.object({
  tripId: z.string(),
  action: z.string(),
  params: z.record(z.string(), z.unknown()),
  response: z.string(),
  dispatched: z.boolean(),
  impactedStageNumbers: z.array(z.number()).optional(),
  requiresFullAnalysis: z.boolean().optional(),
  pois: z.array(poiSuggestionSchema).nullable().optional(),
});

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

export async function downloadTripGpx(
  tripId: string,
  tripTitle: string,
): Promise<void> {
  const res = await apiFetch(`${API_URL}/trips/${tripId}.gpx`);
  if (!res.ok) throw new Error(`Download failed with status ${res.status}`);
  const blob = await res.blob();
  const safeName = tripTitle.trim().replace(/[^a-z0-9\-_]/gi, "-") || "trip";
  triggerBlobDownload(blob, `${safeName}.gpx`);
}

export async function downloadStageFile(
  tripId: string,
  stageIndex: number,
  format: "gpx" | "fit",
  dayNumber: number,
): Promise<void> {
  const res = await apiFetch(
    `${API_URL}/trips/${tripId}/stages/${stageIndex}.${format}`,
  );
  if (!res.ok) throw new Error(`Download failed with status ${res.status}`);
  const blob = await res.blob();
  triggerBlobDownload(blob, `stage-${dayNumber}.${format}`);
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

/**
 * Download shared trip as GPX via short code (anonymous).
 */
export async function downloadSharedTripGpx(
  shortCode: string,
  tripTitle: string,
): Promise<void> {
  const res = await fetch(`${API_URL}/s/${encodeURIComponent(shortCode)}.gpx`);
  if (!res.ok) throw new Error("Download failed");
  const blob = await res.blob();
  const safeName = tripTitle.trim().replace(/[^a-z0-9\-_]/gi, "-") || "trip";
  triggerBlobDownload(blob, `${safeName}.gpx`);
}

/**
 * Download shared stage as GPX or FIT via short code (anonymous).
 */
export async function downloadSharedStageFile(
  shortCode: string,
  stageIndex: number,
  format: "gpx" | "fit",
  dayNumber: number,
): Promise<void> {
  const res = await fetch(
    `${API_URL}/s/${encodeURIComponent(shortCode)}/stages/${stageIndex}.${format}`,
  );
  if (!res.ok) throw new Error("Download failed");
  const blob = await res.blob();
  triggerBlobDownload(blob, `stage-${dayNumber}.${format}`);
}
