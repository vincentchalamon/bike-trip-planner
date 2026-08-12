import { afterEach, describe, expect, it, vi } from "vitest";
import { cookies } from "next/headers";
import { POST } from "./route";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));

const mockedCookies = vi.mocked(cookies);

function stubCookieJar(): { set: ReturnType<typeof vi.fn> } {
  const jar = { set: vi.fn(), get: vi.fn() };
  mockedCookies.mockResolvedValue(
    jar as unknown as Awaited<ReturnType<typeof cookies>>,
  );
  return jar;
}

function sameOriginPost(headers: Record<string, string> = {}): Request {
  return new Request("https://app.example.com/api/auth/logout", {
    method: "POST",
    headers: { "sec-fetch-site": "same-origin", ...headers },
  });
}

describe("POST /api/auth/logout", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it("forwards the bearer, clears the cookie and returns 204", async () => {
    const jar = stubCookieJar();
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(sameOriginPost({ authorization: "Bearer jwt-123" }));

    expect(res.status).toBe(204);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(String(url)).toMatch(/\/auth\/logout$/);
    expect(init.headers).toMatchObject({ Authorization: "Bearer jwt-123" });
    // The device session is dropped regardless of the backend outcome.
    expect(jar.set).toHaveBeenCalledWith(
      "refresh_token",
      "",
      expect.objectContaining({ maxAge: 0 }),
    );
  });

  it("clears the cookie even when the backend call throws", async () => {
    const jar = stubCookieJar();
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("down")));

    const res = await POST(sameOriginPost({ authorization: "Bearer jwt-123" }));

    expect(res.status).toBe(204);
    expect(jar.set).toHaveBeenCalledWith(
      "refresh_token",
      "",
      expect.objectContaining({ maxAge: 0 }),
    );
  });

  it("rejects a cross-site request with 403 and never touches the cookie", async () => {
    const jar = stubCookieJar();
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(
      new Request("https://app.example.com/api/auth/logout", {
        method: "POST",
        headers: { "sec-fetch-site": "cross-site" },
      }),
    );

    expect(res.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
    expect(jar.set).not.toHaveBeenCalled();
  });
});
