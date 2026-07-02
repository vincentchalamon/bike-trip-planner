import { afterEach, describe, expect, it, vi } from "vitest";
import { cookies } from "next/headers";
import { resolveServerSession } from "./server-session";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));

const mockedCookies = vi.mocked(cookies);

function stubRefreshCookie(token: string | undefined): void {
  mockedCookies.mockResolvedValue({
    get: (name: string) =>
      name === "refresh_token" && token ? { value: token } : undefined,
  } as unknown as Awaited<ReturnType<typeof cookies>>);
}

describe("resolveServerSession", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it("returns null on the mobile static build (no server)", async () => {
    vi.stubEnv("NEXT_PUBLIC_IS_MOBILE_BUILD", "1");
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    expect(await resolveServerSession()).toBeNull();
    expect(fetchMock).not.toHaveBeenCalled();
    expect(mockedCookies).not.toHaveBeenCalled();
  });

  it("returns unauthenticated without a network call when the cookie is absent", async () => {
    vi.stubEnv("NEXT_PUBLIC_IS_MOBILE_BUILD", "");
    stubRefreshCookie(undefined);
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    expect(await resolveServerSession()).toEqual({
      authenticated: false,
      user: null,
    });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("fails open (null) when the backend responds non-2xx", async () => {
    vi.stubEnv("NEXT_PUBLIC_IS_MOBILE_BUILD", "");
    stubRefreshCookie("tok");
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: false }));

    expect(await resolveServerSession()).toBeNull();
  });

  it("fails open (null) when fetch throws / times out", async () => {
    vi.stubEnv("NEXT_PUBLIC_IS_MOBILE_BUILD", "");
    stubRefreshCookie("tok");
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("timeout")));

    expect(await resolveServerSession()).toBeNull();
  });

  it("treats a malformed authenticated payload as unauthenticated", async () => {
    vi.stubEnv("NEXT_PUBLIC_IS_MOBILE_BUILD", "");
    stubRefreshCookie("tok");
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ authenticated: true, userId: null, email: null }),
      }),
    );

    expect(await resolveServerSession()).toEqual({
      authenticated: false,
      user: null,
    });
  });

  it("returns the validated session on a 2xx authenticated response", async () => {
    vi.stubEnv("NEXT_PUBLIC_IS_MOBILE_BUILD", "");
    stubRefreshCookie("tok");
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        authenticated: true,
        userId: "user-1",
        email: "alice@example.com",
      }),
    });
    vi.stubGlobal("fetch", fetchMock);

    expect(await resolveServerSession()).toEqual({
      authenticated: true,
      user: { id: "user-1", email: "alice@example.com" },
    });
    // Only the refresh_token is forwarded to the INTERNAL backend.
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(String(url)).toMatch(/\/auth\/session$/);
    expect((init as RequestInit).headers).toMatchObject({
      Cookie: "refresh_token=tok",
    });
  });
});
