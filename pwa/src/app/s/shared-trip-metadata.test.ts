import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { createTranslator } from "next-intl";
import en from "../../../messages/en.json";
import fr from "../../../messages/fr.json";

const catalogs = { en, fr };
let locale: keyof typeof catalogs = "en";

// Neutralize the client component so importing the route module only
// exercises generateMetadata.
vi.mock("./[code]/shared-trip-page", () => ({ default: () => null }));
vi.mock("next-intl/server", () => ({
  getTranslations: (namespace: "sharedTripMetadata") =>
    Promise.resolve(
      createTranslator({ locale, messages: catalogs[locale], namespace }),
    ),
}));

import { generateMetadata } from "@/app/s/[code]/page";

const params = (code: string) => ({ params: Promise.resolve({ code }) });

const mockFetch = (impl: (...args: unknown[]) => unknown) => {
  const fn = vi.fn(impl);
  vi.stubGlobal("fetch", fn);
  return fn;
};

describe("generateMetadata (shared trip)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    locale = "en";
  });
  afterEach(() => vi.unstubAllGlobals());

  it("falls back to the generic title when the backend responds non-OK (revoked/unknown code)", async () => {
    const fetchSpy = mockFetch(() => Promise.resolve({ ok: false }));

    const meta = await generateMetadata(params("missing"));

    expect(meta).toEqual({ title: "Shared trip — Bike Trip Planner" });
    expect(fetchSpy).toHaveBeenCalledOnce();
    expect(fetchSpy.mock.calls[0]?.[0]).toContain("/s/missing");
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

  it("renders the metadata in the request locale", async () => {
    locale = "fr";
    mockFetch(() =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            title: "",
            stages: [{ distance: 42, elevation: 0 }],
          }),
      }),
    );

    const meta = await generateMetadata(params("fr1"));

    expect(meta.title).toBe("Voyage à vélo — Bike Trip Planner");
    expect(meta.description).toBe("Itinéraire vélo partagé : 42 km, 0 m D+.");
  });
});
