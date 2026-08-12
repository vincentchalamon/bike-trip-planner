import { afterEach, describe, expect, it, vi } from "vitest";
import { POST } from "./route";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));

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

describe("POST /api/auth/request-link", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it("forwards { email } and mirrors the backend 202", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(null, { status: 202 }));
    vi.stubGlobal("fetch", fetchMock);

    const res = await POST(sameOriginPost({ email: "alice@example.com" }));

    expect(res.status).toBe(202);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(String(url)).toMatch(/\/auth\/request-link$/);
    expect(init.method).toBe("POST");
    expect(JSON.parse(init.body as string)).toEqual({
      email: "alice@example.com",
    });
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
