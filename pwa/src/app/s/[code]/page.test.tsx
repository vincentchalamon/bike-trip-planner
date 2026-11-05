import type { ReactNode } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { headers } from "next/headers";
import Page from "./page";

vi.mock("next/headers", () => ({ headers: vi.fn() }));
vi.mock("next-intl/server", () => ({ getTranslations: vi.fn() }));
vi.mock("@/components/site-chrome", () => ({
  SiteChrome: ({ children }: { children: ReactNode }) => children,
}));
vi.mock("./shared-trip-page", () => ({ default: () => null }));

function render(code: string) {
  return Page({ params: Promise.resolve({ code }) });
}

describe("/s/[code] SSR fetch", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it("forwards the client X-Forwarded-For chain to the backend", async () => {
    vi.mocked(headers).mockResolvedValue(
      new Headers({
        "x-forwarded-for": "203.0.113.7, 172.18.0.5",
      }) as unknown as Awaited<ReturnType<typeof headers>>,
    );
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response("{}", { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    await render("Ab3kX9mP");

    expect(fetchMock).toHaveBeenCalledOnce();
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://php/s/Ab3kX9mP");
    expect(init.headers).toMatchObject({
      "X-Forwarded-For": "203.0.113.7, 172.18.0.5",
    });
  });

  it.each(["short", "Ab3kX9mPx", "Ab3k.9mP", "..%2F..%2F", "Ab3k/9mP"])(
    "never calls the backend for a malformed code (%s)",
    async (code) => {
      const fetchMock = vi.fn();
      vi.stubGlobal("fetch", fetchMock);

      await render(code);

      expect(fetchMock).not.toHaveBeenCalled();
    },
  );
});
