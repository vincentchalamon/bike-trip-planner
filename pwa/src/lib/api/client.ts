import createClient from "openapi-fetch";
import type { paths } from "./schema";

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

export function parseApiError(status: number, body: unknown): ApiError {
  if (
    status === 422 &&
    body &&
    typeof body === "object" &&
    "violations" in body
  ) {
    const violations =
      (body as { violations?: { propertyPath: string; message: string }[] })
        .violations ?? [];
    return {
      type: "validation",
      message:
        violations.map((v) => v.message).join(", ") || "Validation error",
      violations,
    };
  }

  if (status === 400) {
    const detail =
      body && typeof body === "object" && "detail" in body
        ? (body as { detail?: string }).detail
        : undefined;
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
