import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cookies } from "next/headers";
import { POST } from "./route";

vi.mock("next/headers", () => ({
  cookies: vi.fn(),
  headers: vi.fn(async () => new Headers()),
}));

function sameOriginPost(body: unknown): Request {
  return new Request("https://app.example.com/api/auth/request-link", {
    method: "POST",
    headers: {
      "sec-fetch-site": "same-origin",
      "content-type": "application/json",
    },
    body: JSON.stringify(body),
  });
}

const jar = { set: vi.fn(), get: vi.fn() };

describe("POST /api/auth/request-link", () => {
  beforeEach(() => {
    vi.mocked(cookies).mockResolvedValue(
      jar as unknown as Awaited<ReturnType<typeof cookies>>,
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it("forwards { email, nonce } and mirrors the backend 202", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(null, { status: 202 }));
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(sameOriginPost({ email: "alice@example.com" }));

    expect(res.status).toBe(202);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(String(url)).toMatch(/\/auth\/request-link$/);
    expect(init.method).toBe("POST");
    const sent = JSON.parse(init.body as string) as Record<string, string>;
    expect(sent.email).toBe("alice@example.com");
    // SEC-15: the link is bound to the secret this browser keeps in its cookie.
    expect(sent.nonce).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(jar.set).toHaveBeenCalledWith(
      "__Host-login_nonce",
      sent.nonce,
      expect.objectContaining({ httpOnly: true }),
    );
  });

  it("rejects a cross-site request with 403 and never calls the backend", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(
      new Request("https://app.example.com/api/auth/request-link", {
        method: "POST",
        headers: { "sec-fetch-site": "cross-site" },
        body: JSON.stringify({ email: "alice@example.com" }),
      }),
    );

    expect(res.status).toBe(403);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
