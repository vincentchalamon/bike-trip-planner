import { afterEach, describe, expect, it, vi } from "vitest";
import { headers } from "next/headers";
import { backendFetch, forwardedForHeader } from "./backend";

vi.mock("next/headers", () => ({ headers: vi.fn() }));

function inbound(values: Record<string, string>): Headers {
  return new Headers(values);
}

describe("forwardedForHeader", () => {
  it("relays a single-hop X-Forwarded-For", () => {
    expect(
      forwardedForHeader(inbound({ "x-forwarded-for": "203.0.113.7" })),
    ).toEqual({ "X-Forwarded-For": "203.0.113.7" });
  });

  it("relays a multi-hop chain unchanged, client-prepended entries included", () => {
    const chain = "198.51.100.66, 203.0.113.7, 172.18.0.4, 172.18.0.5";
    expect(forwardedForHeader(inbound({ "x-forwarded-for": chain }))).toEqual({
      "X-Forwarded-For": chain,
    });
  });

  it.each<Record<string, string>>([{}, { "x-forwarded-for": "   " }])(
    "sends nothing when the header is absent or blank (%o)",
    (values) => {
      expect(forwardedForHeader(inbound(values))).toEqual({});
    },
  );
});

describe("backendFetch", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  function stubInbound(values: Record<string, string>) {
    vi.mocked(headers).mockResolvedValue(
      inbound(values) as unknown as Awaited<ReturnType<typeof headers>>,
    );
    const fetchMock = vi.fn().mockResolvedValue(new Response(null));
    vi.stubGlobal("fetch", fetchMock);
    return fetchMock;
  }

  it("forwards the inbound X-Forwarded-For chain to the backend", async () => {
    const fetchMock = stubInbound({
      "x-forwarded-for": "203.0.113.7, 172.18.0.5",
    });

    await backendFetch("/auth/request-link", { method: "POST" });

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
    expect(init.headers).toMatchObject({
      "X-Forwarded-For": "203.0.113.7, 172.18.0.5",
      Accept: "application/ld+json",
    });
  });

  it("adds no X-Forwarded-For when the request carried none", async () => {
    const fetchMock = stubInbound({});

    await backendFetch("/auth/refresh");

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
    expect(init.headers).not.toHaveProperty("X-Forwarded-For");
  });
});
