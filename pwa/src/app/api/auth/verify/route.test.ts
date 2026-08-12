import { afterEach, describe, expect, it, vi } from "vitest";
import { cookies } from "next/headers";
import { POST } from "./route";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));

const mockedCookies = vi.mocked(cookies);

function stubCookieJar(): { set: ReturnType<typeof vi.fn> } {
  const jar = { set: vi.fn(), get: vi.fn(), delete: vi.fn() };
  mockedCookies.mockResolvedValue(
    jar as unknown as Awaited<ReturnType<typeof cookies>>,
  );
  return jar;
}

function sameOriginPost(body: unknown): Request {
  return new Request("https://app.example.com/api/auth/verify", {
    method: "POST",
    headers: {
      "sec-fetch-site": "same-origin",
      "content-type": "application/json",
    },
    body: JSON.stringify(body),
  });
}

describe("POST /api/auth/verify", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it("persists the refresh_token in a cookie and returns only the JWT", async () => {
    const jar = stubCookieJar();
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        new Response(
          JSON.stringify({ token: "jwt-123", refresh_token: "refresh-abc" }),
          { status: 200, headers: { "content-type": "application/json" } },
        ),
      );
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(sameOriginPost({ token: "magic" }));

    expect(res.status).toBe(200);
    // The refresh token is pinned server-side.
    expect(jar.set).toHaveBeenCalledWith(
      "refresh_token",
      "refresh-abc",
      expect.objectContaining({ httpOnly: true }),
    );
    // Only the short-lived JWT is handed to the browser.
    const payload = (await res.json()) as Record<string, unknown>;
    expect(payload).toEqual({ token: "jwt-123" });
    expect(payload).not.toHaveProperty("refresh_token");
    // The magic-link token is forwarded to the backend.
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(String(url)).toMatch(/\/auth\/verify$/);
    expect(JSON.parse(init.body as string)).toEqual({ token: "magic" });
  });

  it("mirrors a backend rejection without setting a cookie", async () => {
    const jar = stubCookieJar();
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(null, { status: 400 }));
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(sameOriginPost({ token: "bad" }));

    expect(res.status).toBe(400);
    expect(jar.set).not.toHaveBeenCalled();
  });

  it("rejects a cross-site request with 403 and never calls the backend", async () => {
    stubCookieJar();
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(
      new Request("https://app.example.com/api/auth/verify", {
        method: "POST",
        headers: { "sec-fetch-site": "cross-site" },
        body: JSON.stringify({ token: "magic" }),
      }),
    );

    expect(res.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
