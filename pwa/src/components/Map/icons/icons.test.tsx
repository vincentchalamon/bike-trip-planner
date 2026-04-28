import { describe, it, expect } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { MarkerIcon, MARKER_CATEGORIES, resolveCategory } from "./index";
import { ICON_SHAPES } from "./markerDom";
import type { MarkerCategory } from "./index";

describe("ICON_SHAPES / JSX component shape drift", () => {
  function assertShapes(markup: string, key: MarkerCategory): void {
    for (const shape of ICON_SHAPES[key]) {
      if (shape.tag === "path" && typeof shape.attrs.d === "string") {
        expect(markup).toContain(`d="${shape.attrs.d}"`);
      } else if (shape.tag === "rect" || shape.tag === "circle") {
        // Assert each numeric attribute renders identically in the JSX SVG.
        for (const [attr, value] of Object.entries(shape.attrs)) {
          expect(markup).toContain(`${attr}="${value}"`);
        }
      }
    }
  }

  it.each([...MARKER_CATEGORIES] as MarkerCategory[])(
    "%s: ICON_SHAPES shapes match JSX component",
    (category) => {
      const Icon = MarkerIcon[category];
      const markup = renderToStaticMarkup(<Icon />);
      assertShapes(markup, category);
    },
  );
});

describe("resolveCategory — full identifier mapping", () => {
  it.each([
    // Accommodation subtypes
    ["hotel", "accommodation"],
    ["alpine_hut", "accommodation"],
    ["camp_site", "accommodation"],
    ["shelter", "accommodation"],
    // Water
    ["water", "water"],
    ["drinking_water", "water"],
    ["water_point", "water"],
    // Supply
    ["supply", "supply"],
    ["supermarket", "supply"],
    ["convenience", "supply"],
    ["food", "supply"],
    // Bike workshop
    ["bicycle_repair", "bike-workshop"],
    ["bicycle", "bike-workshop"],
    ["compressed_air", "bike-workshop"],
    // Railway station
    ["railway", "railway-station"],
    ["railway_station", "railway-station"],
    ["train_station", "railway-station"],
    // Health
    ["pharmacy", "health"],
    ["hospital", "health"],
    ["clinic", "health"],
    // Border crossing
    ["border", "border-crossing"],
    ["border_crossing", "border-crossing"],
    ["country_border", "border-crossing"],
    // River crossing
    ["river_crossing", "river-crossing"],
    ["ford", "river-crossing"],
    ["water_crossing", "river-crossing"],
    // Early departure
    ["early_departure", "early-departure"],
    ["sunset_alert", "early-departure"],
    ["wakeup", "early-departure"],
    // Cultural POI
    ["cultural_poi", "cultural-poi"],
    ["datatourisme", "cultural-poi"],
    ["wikidata", "cultural-poi"],
    ["monument", "cultural-poi"],
    ["museum", "cultural-poi"],
    // Event
    ["event", "event"],
    ["festival", "event"],
    ["market", "event"],
    ["public_holiday", "event"],
    // User waypoint
    ["waypoint", "user-waypoint"],
    ["user_waypoint", "user-waypoint"],
    ["user", "user-waypoint"],
  ] as const)("resolveCategory('%s') === '%s'", (source, expected) => {
    expect(resolveCategory(source)).toBe(expected);
  });

  it("returns null for empty / null / undefined input", () => {
    expect(resolveCategory("")).toBeNull();
    expect(resolveCategory(null)).toBeNull();
    expect(resolveCategory(undefined)).toBeNull();
  });

  it("returns null for unknown identifiers", () => {
    expect(resolveCategory("foobar")).toBeNull();
    expect(resolveCategory("not_a_category")).toBeNull();
  });

  it("is case-insensitive", () => {
    expect(resolveCategory("HOTEL")).toBe("accommodation");
    expect(resolveCategory("Cultural_POI")).toBe("cultural-poi");
  });
});
