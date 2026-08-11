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

  it("fails open (null) without a network call when the cookie is absent", async () => {
    stubRefreshCookie(undefined);
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    // No cookie → nothing to validate → let the client decide.
    expect(await resolveServerSession()).toBeNull();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("fails open (null) when the backend responds non-2xx", async () => {
    stubRefreshCookie("tok");
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: false }));

    expect(await resolveServerSession()).toBeNull();
  });

  it("fails open (null) when fetch throws / times out", async () => {
    stubRefreshCookie("tok");
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("timeout")));

    expect(await resolveServerSession()).toBeNull();
  });

  it("treats a malformed authenticated payload as unauthenticated", async () => {
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
