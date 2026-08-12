import { afterEach, describe, expect, it, vi } from "vitest";
import { cookies } from "next/headers";
import { POST } from "./route";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));

const mockedCookies = vi.mocked(cookies);

function stubCookieJar(refreshValue: string | undefined): {
  set: ReturnType<typeof vi.fn>;
} {
  const jar = {
    set: vi.fn(),
    get: (name: string) =>
      name === "refresh_token" && refreshValue
        ? { value: refreshValue }
        : undefined,
  };
  mockedCookies.mockResolvedValue(
    jar as unknown as Awaited<ReturnType<typeof cookies>>,
  );
  return jar;
}

function sameOriginPost(): Request {
  return new Request("https://app.example.com/api/auth/refresh", {
    method: "POST",
    headers: { "sec-fetch-site": "same-origin" },
  });
}

describe("POST /api/auth/refresh", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it("rotates the cookie and returns only the JWT when the cookie is present", async () => {
    const jar = stubCookieJar("refresh-old");
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        new Response(
          JSON.stringify({ token: "jwt-new", refresh_token: "refresh-new" }),
          { status: 200, headers: { "content-type": "application/json" } },
        ),
      );
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(sameOriginPost());

    expect(res.status).toBe(200);
    // Sends the current refresh token to the backend.
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(String(url)).toMatch(/\/auth\/refresh$/);
    expect(JSON.parse(init.body as string)).toEqual({
      refresh_token: "refresh-old",
    });
    // Re-pins the rotated refresh token server-side.
    expect(jar.set).toHaveBeenCalledWith(
      "refresh_token",
      "refresh-new",
      expect.objectContaining({ httpOnly: true, maxAge: expect.any(Number) }),
    );
    // Only the JWT reaches the browser.
    const payload = (await res.json()) as Record<string, unknown>;
    expect(payload).toEqual({ token: "jwt-new" });
    expect(payload).not.toHaveProperty("refresh_token");
  });

  it("returns 401 without a cookie and never calls the backend", async () => {
    stubCookieJar(undefined);
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(sameOriginPost());

    expect(res.status).toBe(401);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("clears the cookie and returns 401 when the backend rejects the token", async () => {
    const jar = stubCookieJar("refresh-old");
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(null, { status: 401 }));
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(sameOriginPost());

    expect(res.status).toBe(401);
    // The stale cookie is dropped (empty value, maxAge 0).
    expect(jar.set).toHaveBeenCalledWith(
      "refresh_token",
      "",
      expect.objectContaining({ maxAge: 0 }),
    );
  });

  it("rejects a cross-site request with 403 and never calls the backend", async () => {
    stubCookieJar("refresh-old");
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(
      new Request("https://app.example.com/api/auth/refresh", {
        method: "POST",
        headers: { "sec-fetch-site": "cross-site" },
      }),
    );

    expect(res.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
