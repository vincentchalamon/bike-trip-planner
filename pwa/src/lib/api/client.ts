import createClient from "openapi-fetch";
import type { paths } from "./schema";
import { API_URL } from "@/lib/constants";

function getBrowserLocale(): string {
  if (typeof navigator !== "undefined") {
    return navigator.language;
  }
  return "fr";
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

export async function scrapeAccommodation(
  url: string,
): Promise<ScrapedData | null> {
  const res = await fetch("/accommodations/scrape", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ url }),
  });
  if (!res.ok) return null;
  return res.json() as Promise<ScrapedData>;
}

export async function downloadStageFile(
  tripId: string,
  stageIndex: number,
  format: "gpx" | "fit",
  dayNumber: number,
): Promise<void> {
  const res = await fetch(
    `${API_URL}/trips/${tripId}/stages/${stageIndex}.${format}`,
  );
  if (!res.ok) {
    throw new Error(`Download failed: ${res.status} ${res.statusText}`);
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
