import createClient from "openapi-fetch";
import type { operations, paths } from "./schema";
import { API_URL } from "@/lib/constants";

function getBrowserLocale(): string {
  if (typeof navigator !== "undefined") {
    return navigator.language;
  }
  return "fr";
}

export async function apiFetch(
  input: string,
  init?: RequestInit,
): Promise<Response> {
  return fetch(input, {
    ...init,
    headers: {
      "Accept-Language": getBrowserLocale(),
      ...init?.headers,
    },
  });
}

export const apiClient = createClient<paths>({
  headers: {
    "Content-Type": "application/ld+json",
    Accept: "application/ld+json",
    "Accept-Language": getBrowserLocale(),
  },
});

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
    `/trips/${encodeURIComponent(tripId)}/accommodations/scan`,
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
  const res = await apiFetch("/accommodations/scrape", {
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
): Promise<{ data: GpxUploadResponse | null; error: string | null }> {
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

  const res = await apiFetch("/trips/gpx-upload", {
    method: "POST",
    body: formData,
  });

  if (!res.ok) {
    const body = (await res.json().catch(() => null)) as {
      error?: string;
    } | null;
    return { data: null, error: body?.error ?? "Upload failed" };
  }

  const data = (await res.json()) as GpxUploadResponse;
  return { data, error: null };
}

/**
 * Download the full trip as a single GPX file containing all stages and trigger
 * a browser save dialog.
 * @throws {Error} When the server responds with a non-2xx status.
 */
export async function downloadTripGpx(
  tripId: string,
  tripTitle: string,
): Promise<void> {
  const res = await apiFetch(`${API_URL}/trips/${tripId}.gpx`);
  if (!res.ok) {
    throw new Error(`Download failed with status ${res.status}`);
  }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  const safeName = tripTitle.trim().replace(/[^a-z0-9\-_]/gi, "-") || "trip";
  a.download = `${safeName}.gpx`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

/**
 * Download a stage file (GPX or FIT) and trigger a browser save dialog.
 * @throws {Error} When the server responds with a non-2xx status.
 */
export async function downloadStageFile(
  tripId: string,
  stageIndex: number,
  format: "gpx" | "fit",
  dayNumber: number,
): Promise<void> {
  const res = await apiFetch(
    `${API_URL}/trips/${tripId}/stages/${stageIndex}.${format}`,
  );
  if (!res.ok) {
    throw new Error(`Download failed with status ${res.status}`);
  }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `stage-${dayNumber}.${format}`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
