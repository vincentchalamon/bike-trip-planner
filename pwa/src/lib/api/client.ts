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
 * Used for non-OpenAPI calls (GPX upload, accommodation scan, etc.) where
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
 * Body of `POST /trips/{id}/ai-chat`. Hand-written mirror of
 * `App\ApiResource\TripChatRequest`: this route is called through
 * {@link apiFetch} rather than the generated typed client, so the shape is
 * maintained here instead of being sourced from the OpenAPI types.
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
 * Response of `POST /trips/{id}/ai-chat`. Mirrors `App\ApiResource\TripChatResponse`
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
 * Calls the server through {@link apiFetch} rather than the generated
 * `apiClient.POST`, using the hand-written {@link TripChatRequestBody} /
 * {@link TripChatResponseBody} shapes above. The OpenAPI schema does expose
 * this route, so this is a deliberate choice, not a typegen limitation.
 */
export async function sendTripChat(
  tripId: string,
  body: TripChatRequestBody,
  signal?: AbortSignal,
): Promise<{
  data: TripChatResponseBody | null;
  error: string | null;
  /**
   * Discrete machine-readable error code from the backend body (`{ error }`),
   * e.g. `ai_invalid_token` / `ai_quota_exceeded` (#761). Null on success or
   * when the error body carries no code (non-JSON / network error).
   */
  errorCode: string | null;
  status: number;
}> {
  const res = await apiFetch(`${API_URL}/trips/${tripId}/ai-chat`, {
    method: "POST",
    headers: {
      "Content-Type": "application/ld+json",
      Accept: "application/ld+json",
    },
    body: JSON.stringify(body),
    signal,
  });

  if (!res.ok) {
    // Surface the backend's discrete error code (#761) so the in-ride panel can
    // tell an actionable config error (422 `ai_invalid_token` / `ai_quota_exceeded`)
    // apart from a transient outage and show the matching settings CTA.
    let errorCode: string | null = null;
    try {
      const errorBody: unknown = await res.json();
      if (
        errorBody !== null &&
        typeof errorBody === "object" &&
        "error" in errorBody &&
        typeof errorBody.error === "string"
      ) {
        errorCode = errorBody.error;
      }
    } catch {
      // Non-JSON error body (proxy / HTML page) — leave errorCode null.
    }
    return {
      data: null,
      error: `HTTP ${res.status}`,
      errorCode,
      status: res.status,
    };
  }

  const raw: unknown = await res.json();
  const parsed = tripChatResponseSchema.safeParse(raw);
  if (!parsed.success) {
    return {
      data: null,
      error: "Invalid response shape",
      errorCode: null,
      status: res.status,
    };
  }

  return {
    data: parsed.data,
    error: null,
    errorCode: null,
    status: res.status,
  };
}

/**
 * One persisted chat turn returned by `GET /trips/{id}/ai-chat-history`.
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
  const url = `${API_URL}/trips/${tripId}/ai-chat-history${query ? `?${query}` : ""}`;

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
 * One turn of the pre-trip brief chat (`POST /trips/ai-chat`, ADR-045).
 * Sourced from the generated OpenAPI schema (`AiChatMessage`) so role/content
 * stay in lockstep with `App\ApiResource\Model\AiChatMessage`.
 */
export type AiChatTurn = components["schemas"]["AiChatMessage"];

/**
 * Response of `POST /trips/ai-chat` (`App\ApiResource\AiChatResponse`): the
 * assistant reply, the model's readiness verdict and the running structured
 * summary of the brief understood so far.
 */
export type AiChatResponseBody = Pick<
  components["schemas"]["Trip.AiChatResponse.jsonld"],
  "reply" | "readyToGenerate"
> & {
  // Intentionally narrower than the generated `{ [key: string]: unknown }`:
  // the recap/brief only consume flat scalar values.
  collected: Record<string, string | number | boolean | null>;
};

/**
 * Outcome of {@link sendAiChat}. A discriminated union so the caller maps the
 * backend's discrete failure modes (ADR-045) to localized messages without
 * re-deriving them from a raw status code:
 *
 * - `ok`              — the assistant turn (reply + readiness + collected).
 * - `not_configured`  — 422 `{error:"ai_not_configured"}`: no provider set;
 *   surface the "configure une IA" CTA (mirrors `aiCapability.configured`).
 * - `invalid_token`   — 422 `{error:"ai_invalid_token"}`: the stored key is
 *   wrong/revoked; surface the settings CTA (retrying is pointless).
 * - `quota_exceeded`  — 422 `{error:"ai_quota_exceeded"}`: the provider plan is
 *   exhausted; surface the settings CTA to switch provider.
 * - `rate_limited`    — 429: per-user chat rate limit reached (transient).
 * - `unavailable`     — 503: provider unreachable / transient outage.
 * - `error`           — any other failure (network, 4xx, bad shape).
 */
export type AiChatResult =
  | { status: "ok"; data: AiChatResponseBody }
  | { status: "not_configured" }
  | { status: "invalid_token" }
  | { status: "quota_exceeded" }
  | { status: "rate_limited" }
  | { status: "unavailable" }
  | { status: "error" };

const aiChatResponseSchema = z.object({
  reply: z.string(),
  readyToGenerate: z.boolean(),
  collected: z
    .record(
      z.string(),
      z.union([z.string(), z.number(), z.boolean(), z.null()]),
    )
    .catch({})
    .default({}),
});

/** Read the discrete `{error}` code carried by an ai-chat failure body. */
function aiChatErrorCode(body: unknown): string | null {
  if (body !== null && typeof body === "object" && "error" in body) {
    const value = (body as { error?: unknown }).error;
    return typeof value === "string" ? value : null;
  }
  return null;
}

/**
 * Send the whole brief-chat transcript to `POST /trips/ai-chat` (ADR-045).
 *
 * The endpoint is stateless — the client carries the full conversation on every
 * turn. Uses the typed {@link apiClient} (the route is fully described by the
 * generated schema, unlike `sendTripChat` which keeps a hand-written mirror for
 * legacy reasons), then classifies the discrete failure modes by HTTP status so
 * the caller can localize them.
 */
export async function sendAiChat(
  messages: ReadonlyArray<AiChatTurn>,
  signal?: AbortSignal,
): Promise<AiChatResult> {
  let response: Response;
  let error: unknown;
  let data: unknown;
  try {
    const result = await apiClient.POST("/trips/ai-chat", {
      body: { messages: [...messages] },
      ...(signal ? { signal } : {}),
    });
    response = result.response;
    error = result.error;
    data = result.data;
  } catch {
    // Network failure / aborted request — the openapi-fetch promise rejects.
    return { status: "error" };
  }

  if (response.ok && data) {
    const parsed = aiChatResponseSchema.safeParse(data);
    if (!parsed.success) return { status: "error" };
    return { status: "ok", data: parsed.data };
  }

  if (response.status === 422) {
    switch (aiChatErrorCode(error)) {
      case "ai_not_configured":
        return { status: "not_configured" };
      case "ai_invalid_token":
        return { status: "invalid_token" };
      case "ai_quota_exceeded":
        return { status: "quota_exceeded" };
      default:
        return { status: "error" };
    }
  }
  if (response.status === 429) return { status: "rate_limited" };
  if (response.status === 503) return { status: "unavailable" };
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

export async function downloadTripFile(
  tripId: string,
  tripTitle: string,
  format: "gpx" | "fit",
): Promise<void> {
  const res = await apiFetch(`${API_URL}/trips/${tripId}.${format}`);
  if (!res.ok) throw new Error(`Download failed with status ${res.status}`);
  const blob = await res.blob();
  const safeName = tripTitle.trim().replace(/[^a-z0-9\-_]/gi, "-") || "trip";
  triggerBlobDownload(blob, `${safeName}.${format}`);
}

export async function downloadStageFile(
  tripId: string,
  stageIndex: number,
  format: "gpx" | "fit",
  dayNumber: number,
): Promise<void> {
  const res = await apiFetch(
    `${API_URL}/trips/${tripId}/stages/${stageIndex}/export.${format}`,
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
 * Per-user AI configuration (ADR-042): the cloud provider chosen for the
 * bring-your-own-token model. Mirrors `App\Llm\AiProvider`. Kept as a const
 * tuple so the settings dropdown and the typed payloads stay in lockstep.
 */
export const AI_PROVIDERS = ["anthropic", "gemini", "openai"] as const;
export type AiProvider = (typeof AI_PROVIDERS)[number];

/**
 * Shape of `GET /users/me/ai-settings` (`App\ApiResource\Account\AiSettings`).
 *
 * Declared locally until `make typegen` ingests the schema change introduced by
 * ADR-042 — the resource is absent from the current generated `schema.d.ts`
 * (the spec export needs the backend DB, unavailable at build time here). Once
 * the typegen catches up this can be swapped for
 * `components["schemas"]["AiSettings.jsonld"]`.
 *
 * `provider` is omitted from the payload when AI is unconfigured, hence
 * optional/null here.
 */
export interface AiSettingsResponse {
  provider?: AiProvider | null;
  tokenConfigured: boolean;
}

/**
 * Fetch the current user's AI settings. Returns `null` on any failure so the
 * caller treats a transient error as "unconfigured" (fail-closed: AI surfaces
 * stay disabled-but-visible until the settings load confirms a provider).
 */
export async function fetchAiSettings(): Promise<AiSettingsResponse | null> {
  const res = await apiFetch(`${API_URL}/users/me/ai-settings`, {
    headers: { Accept: "application/ld+json" },
  });
  if (!res.ok) return null;
  return res.json() as Promise<AiSettingsResponse>;
}

/**
 * Persist the user's AI provider + token (`PUT /users/me/ai-settings`). The
 * token is write-only — the response never echoes it back.
 *
 * @returns the updated settings on 200, or the parsed {@link ApiError} on 422
 * (structured `violations[]` for blank/unknown provider or blank token; a
 * `detail`-only message for an invalid token format).
 */
export async function saveAiSettings(
  provider: string,
  token: string,
): Promise<
  { data: AiSettingsResponse; error: null } | { data: null; error: ApiError }
> {
  const res = await apiFetch(`${API_URL}/users/me/ai-settings`, {
    method: "PUT",
    headers: {
      "Content-Type": "application/ld+json",
      Accept: "application/ld+json",
    },
    body: JSON.stringify({ provider, token }),
  });
  if (res.ok) {
    return { data: (await res.json()) as AiSettingsResponse, error: null };
  }
  const body = (await res.json().catch(() => null)) as unknown;
  return { data: null, error: parseApiError(res.status, body) };
}

/**
 * Clear the user's AI settings (`DELETE /users/me/ai-settings`).
 * @returns true on HTTP 204, false otherwise.
 */
export async function clearAiSettings(): Promise<boolean> {
  const res = await apiFetch(`${API_URL}/users/me/ai-settings`, {
    method: "DELETE",
  });
  return res.ok;
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
  const safeName = tripTitle.trim().replace(/[^a-z0-9\-_]/gi, "-") || "trip";
  triggerBlobDownload(blob, `${safeName}.${format}`);
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
