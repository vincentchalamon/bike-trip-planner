import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// Neutralize the lazy client component so importing the route module only
// exercises generateMetadata (next/dynamic is not available outside Next).
vi.mock("next/dynamic", () => ({ default: () => () => null }));

import { generateMetadata } from "@/app/s/[code]/page";

const params = (code: string) => ({ params: Promise.resolve({ code }) });

const mockFetch = (impl: () => unknown) => {
  const fn = vi.fn(impl as never);
  vi.stubGlobal("fetch", fn);
  return fn;
};

describe("generateMetadata (shared trip)", () => {
  beforeEach(() => vi.clearAllMocks());
  afterEach(() => vi.unstubAllGlobals());

  it("returns the generic fallback for the static-export placeholder without fetching", async () => {
    const fetchSpy = mockFetch(() => {
      throw new Error("should not be called");
    });

    const meta = await generateMetadata(params("__placeholder"));

    expect(meta).toEqual({ title: "Shared trip — Bike Trip Planner" });
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it("falls back to the generic title when the backend responds non-OK (revoked/unknown code)", async () => {
    const fetchSpy = mockFetch(() => Promise.resolve({ ok: false }));

    const meta = await generateMetadata(params("missing"));

    expect(meta).toEqual({ title: "Shared trip — Bike Trip Planner" });
    expect(fetchSpy).toHaveBeenCalledOnce();
    expect(fetchSpy.mock.calls[0][0]).toContain("/s/missing");
  });

  it("falls back to the generic title when the fetch throws (timeout/network)", async () => {
    mockFetch(() => Promise.reject(new Error("aborted")));

    const meta = await generateMetadata(params("slow"));

    expect(meta).toEqual({ title: "Shared trip — Bike Trip Planner" });
  });

  it("builds title/description/OG from the trip when the fetch succeeds", async () => {
    mockFetch(() =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            title: "Tour des Flandres",
            stages: [
              { distance: 50, elevation: 600 },
              { distance: 30, elevation: 400 },
            ],
          }),
      }),
    );

    const meta = await generateMetadata(params("abc123"));

    expect(meta.title).toBe("Tour des Flandres — Bike Trip Planner");
    expect(meta.description).toBe("Shared bike route: 80 km, 1000 m D+.");
    expect(meta.openGraph).toMatchObject({
      url: "/s/abc123",
      siteName: "Bike Trip Planner",
      type: "article",
    });
    expect(meta.twitter).toMatchObject({ card: "summary" });
  });

  it("uses the default title and a stage-less description when the trip has no stages", async () => {
    mockFetch(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ title: "", stages: [] }),
      }),
    );

    const meta = await generateMetadata(params("xyz"));

    expect(meta.title).toBe("Bike trip — Bike Trip Planner");
    expect(meta.description).toBe("Shared bike route.");
  });
});
