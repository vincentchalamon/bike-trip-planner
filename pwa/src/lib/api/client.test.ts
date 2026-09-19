import { afterEach, describe, expect, it, vi } from "vitest";
import { localizedApiErrorMessage, parseApiError } from "./client";

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

describe("apiClient 401 retry (recette #649 #8)", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
    vi.resetModules();
  });

  it("resends the original body on the post-refresh retry, not an empty one", async () => {
    const bodies: unknown[] = [];
    const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
      bodies.push(init?.body);
      // First call: 401 (the access token isn't ready yet, as on a fresh
      // `?link=` load). The post-refresh retry must succeed.
      return new Response("{}", {
        status: bodies.length === 1 ? 401 : 201,
        headers: { "Content-Type": "application/json" },
      });
    });
    // Stub BEFORE importing the client so openapi-fetch captures the mock + a
    // valid absolute base URL (the test env injects NEXT_PUBLIC_API_URL="").
    vi.stubGlobal("fetch", fetchMock);
    vi.stubEnv("NEXT_PUBLIC_API_URL", "https://localhost");
    vi.resetModules();

    const { useAuthStore } = await import("@/store/auth-store");
    useAuthStore.setState({
      accessToken: "stale-token",
      silentRefresh: vi.fn(async () => {
        useAuthStore.setState({ accessToken: "fresh-token" });
        return true;
      }),
    } as never);

    const { apiClient } = await import("./client");
    await apiClient.POST("/trips", {
      body: { sourceUrl: "https://www.komoot.com/fr-fr/tour/1" } as never,
    });

    // The 401 triggers exactly one retry...
    expect(fetchMock).toHaveBeenCalledTimes(2);
    // ...and it must carry the original payload. The bug cached the body as a
    // single-use ReadableStream, so the retry POST went out empty → 400.
    expect(String(bodies[1] ?? "")).toContain("komoot");
  });
});

describe("trip version precondition (ADR-067)", () => {
  const TRIP_ID = "01936f6e-0000-7000-8000-0000000000aa";

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
    vi.resetModules();
  });

  it("pins the version last seen for a trip, and * for one never read", async () => {
    const { getTripVersion, preconditionHeader, rememberTripVersion } =
      await import("./client");

    rememberTripVersion(
      `https://localhost/trips/${TRIP_ID}/detail`,
      new Response(null, { status: 200, headers: { ETag: '"7"' } }),
    );

    expect(getTripVersion(TRIP_ID)).toBe(7);
    expect(preconditionHeader(TRIP_ID)).toEqual({ "If-Match": '"7"' });
    expect(preconditionHeader("01936f6e-0000-7000-8000-0000000000bb")).toEqual({
      "If-Match": "*",
    });
  });

  // Regression (#1292 review): openapi-fetch runs `onResponse` in *reverse* registration
  // order, so a precondition middleware registered after authMiddleware runs on the raw 401
  // and never on the response the refresh-and-retry rebuilds. The version then stays behind
  // on exactly the case this contract exists for — a token expiring mid-edit — and every
  // later edit is refused. Registration order is the whole fix, and nothing else pins it.
  it("captures the ETag of the response rebuilt after a 401 refresh and retry", async () => {
    let calls = 0;
    const fetchMock = vi.fn(async () => {
      calls += 1;

      return calls === 1
        ? new Response("{}", { status: 401 })
        : new Response("{}", { status: 202, headers: { ETag: '"12"' } });
    });
    vi.stubGlobal("fetch", fetchMock);
    vi.stubEnv("NEXT_PUBLIC_API_URL", "https://localhost");
    vi.resetModules();

    const { useAuthStore } = await import("@/store/auth-store");
    useAuthStore.setState({
      accessToken: "stale-token",
      silentRefresh: vi.fn(async () => {
        useAuthStore.setState({ accessToken: "fresh-token" });
        return true;
      }),
    } as never);

    const { apiClient, getTripVersion, preconditionHeader } =
      await import("./client");
    await apiClient.DELETE("/trips/{tripId}/stages/{stageId}", {
      params: {
        path: { tripId: TRIP_ID, stageId: "stage-1" },
        header: preconditionHeader(TRIP_ID),
      },
    });

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(getTripVersion(TRIP_ID)).toBe(12);
  });
});
