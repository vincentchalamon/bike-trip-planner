import { describe, it, expect } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { AccommodationIcon, WaterIcon } from "./index";
import { ICON_SHAPES } from "./markerDom";

/**
 * Drift detector: asserts that every `d` attribute in ICON_SHAPES is present
 * in the serialised JSX component output. Covers accommodation + water as
 * representative categories; the same pattern applies to all 12 categories.
 */
describe("ICON_SHAPES / JSX component path drift", () => {
  function assertPaths(
    markup: string,
    key: keyof typeof ICON_SHAPES,
  ): void {
    for (const shape of ICON_SHAPES[key]) {
      if (shape.tag === "path" && typeof shape.attrs.d === "string") {
        expect(markup).toContain(`d="${shape.attrs.d}"`);
      }
    }
  }

  it("AccommodationIcon paths match ICON_SHAPES.accommodation", () => {
    const markup = renderToStaticMarkup(<AccommodationIcon />);
    assertPaths(markup, "accommodation");
  });

  it("WaterIcon paths match ICON_SHAPES.water", () => {
    const markup = renderToStaticMarkup(<WaterIcon />);
    assertPaths(markup, "water");
  });
});
