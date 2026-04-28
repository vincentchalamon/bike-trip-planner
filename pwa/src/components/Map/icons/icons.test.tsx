import { describe, it, expect } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { MarkerIcon, MARKER_CATEGORIES, resolveCategory } from "./index";
import { ICON_SHAPES } from "./markerDom";
import type { MarkerCategory } from "./index";

describe("ICON_SHAPES / JSX component path drift", () => {
  function assertPaths(
    markup: string,
    key: MarkerCategory,
  ): void {
    for (const shape of ICON_SHAPES[key]) {
      if (shape.tag === "path" && typeof shape.attrs.d === "string") {
        expect(markup).toContain(`d="${shape.attrs.d}"`);
      }
    }
  }

  it.each([...MARKER_CATEGORIES] as MarkerCategory[])(
    "%s: ICON_SHAPES paths match JSX component",
    (category) => {
      const Icon = MarkerIcon[category];
      const markup = renderToStaticMarkup(<Icon />);
      assertPaths(markup, category);
    },
  );
});

describe("resolveCategory — backend source identifiers", () => {
  it.each([
    ["water_point", "water"],
    ["cultural_poi", "cultural-poi"],
    ["railway_station", "railway-station"],
    ["border_crossing", "border-crossing"],
  ] as const)("resolveCategory('%s') === '%s'", (source, expected) => {
    expect(resolveCategory(source)).toBe(expected);
  });
});
