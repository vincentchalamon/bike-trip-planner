import { afterEach, describe, expect, it, vi } from "vitest";
import {
  localizedApiErrorMessage,
  parseApiError,
  sendTripChat,
} from "./client";

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

  it("returns an empty message for 422 with empty violations", () => {
    const result = parseApiError(422, { violations: [] });
    expect(result).toEqual({
      type: "validation",
      message: "",
      violations: [],
    });
  });

  it("returns bad_request with detail for 400", () => {
    expect(parseApiError(400, { detail: "Invalid source" })).toEqual({
      type: "bad_request",
      message: "Invalid source",
    });
  });

  it("returns bad_request with an empty message when body has no detail", () => {
    expect(parseApiError(400, {})).toEqual({
      type: "bad_request",
      message: "",
    });
  });

  it("returns not_found for 404", () => {
    expect(parseApiError(404, null)).toEqual({
      type: "not_found",
      message: "",
    });
  });

  it("returns network error for unknown status codes", () => {
    expect(parseApiError(500, null)).toEqual({
      type: "network",
      message: "",
    });
  });

  it("returns network error for 422 without violations array", () => {
    // 422 but body doesn't match ViolationBody shape → falls through
    expect(parseApiError(422, { detail: "something" })).toEqual({
      type: "network",
      message: "",
    });
  });
});

describe("localizedApiErrorMessage", () => {
  const t = (key: string) => `t:${key}`;

  it("returns the API-provided message when present", () => {
    expect(
      localizedApiErrorMessage({ type: "bad_request", message: "Bad URL" }, t),
    ).toBe("Bad URL");
  });

  it("falls back to the localized key when the message is empty", () => {
    expect(
      localizedApiErrorMessage({ type: "not_found", message: "" }, t),
    ).toBe("t:errors.notFound");
    expect(localizedApiErrorMessage({ type: "network", message: "" }, t)).toBe(
      "t:errors.unexpectedError",
    );
    expect(
      localizedApiErrorMessage({ type: "validation", message: "" }, t),
    ).toBe("t:errors.validationError");
  });
});

describe("sendTripChat error-code extraction (#761)", () => {
  afterEach(() => vi.unstubAllGlobals());

  it.each([
    ["ai_invalid_token", 422],
    ["ai_quota_exceeded", 422],
    ["ai_unavailable", 503],
  ])(
    "surfaces the discrete %s error code from a %i body",
    async (code, status) => {
      vi.stubGlobal(
        "fetch",
        vi
          .fn()
          .mockResolvedValue(
            new Response(JSON.stringify({ error: code }), { status }),
          ),
      );

      const result = await sendTripChat("trip-1", { message: "Bonjour" });

      expect(result.status).toBe(status);
      expect(result.errorCode).toBe(code);
      expect(result.data).toBeNull();
    },
  );

  it("leaves errorCode null when the error body is not JSON", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(new Response("<html>502</html>", { status: 502 })),
    );

    const result = await sendTripChat("trip-1", { message: "Bonjour" });

    expect(result.errorCode).toBeNull();
    expect(result.error).toBe("HTTP 502");
  });
});
