import { describe, expect, it } from "vitest";
import { parseApiError } from "./client";

describe("parseApiError", () => {
  it("returns validation error with joined messages for 422", () => {
    const body = {
      violations: [
        { propertyPath: "sourceUrl", message: "URL is required" },
        { propertyPath: "startDate", message: "Date is invalid" },
      ],
    };
    expect(parseApiError(422, body)).toEqual({
      type: "validation",
      message: "URL is required, Date is invalid",
      violations: body.violations,
    });
  });

  it("returns fallback message for 422 with empty violations", () => {
    const result = parseApiError(422, { violations: [] });
    expect(result).toEqual({
      type: "validation",
      message: "Validation error",
      violations: [],
    });
  });

  it("returns bad_request with detail for 400", () => {
    expect(parseApiError(400, { detail: "Invalid source" })).toEqual({
      type: "bad_request",
      message: "Invalid source",
    });
  });

  it("returns bad_request with fallback when body has no detail", () => {
    expect(parseApiError(400, {})).toEqual({
      type: "bad_request",
      message: "Bad request",
    });
  });

  it("returns not_found for 404", () => {
    expect(parseApiError(404, null)).toEqual({
      type: "not_found",
      message: "Resource not found",
    });
  });

  it("returns network error for unknown status codes", () => {
    expect(parseApiError(500, null)).toEqual({
      type: "network",
      message: "An unexpected error occurred",
    });
  });

  it("returns network error for 422 without violations array", () => {
    // 422 but body doesn't match ViolationBody shape → falls through
    expect(parseApiError(422, { detail: "something" })).toEqual({
      type: "network",
      message: "An unexpected error occurred",
    });
  });
});
