import { describe, it, expect, vi } from "vitest";
import { createCulturalPoiMarkerElement } from "./poi-marker";

describe("createCulturalPoiMarkerElement", () => {
  it("renders the cultural-poi disc with a pulsation halo", () => {
    const el = createCulturalPoiMarkerElement({
      label: "Pont du Gard",
      background: "#d97706",
      enriched: false,
      onClick: () => {},
    });
    expect(el.classList.contains("map-marker--cultural-poi")).toBe(true);
    expect(el.querySelector(".map-marker__pulse")).not.toBeNull();
    expect(el.dataset.enriched).toBe("false");
    expect(el.querySelector("svg")).not.toBeNull();
  });

  it("flags enriched POIs via the dedicated class and data attribute", () => {
    const el = createCulturalPoiMarkerElement({
      label: "Mont-Saint-Michel",
      background: "#d97706",
      enriched: true,
      onClick: () => {},
    });
    expect(el.classList.contains("map-marker--cultural-enriched")).toBe(true);
    expect(el.dataset.enriched).toBe("true");
  });

  it("invokes onClick on click and on Enter key", () => {
    const onClick = vi.fn();
    const el = createCulturalPoiMarkerElement({
      label: "Test",
      background: "#000",
      enriched: false,
      onClick,
    });
    el.dispatchEvent(new MouseEvent("click", { bubbles: true }));
    expect(onClick).toHaveBeenCalledTimes(1);

    el.dispatchEvent(
      new KeyboardEvent("keydown", { key: "Enter", bubbles: true }),
    );
    expect(onClick).toHaveBeenCalledTimes(2);
  });

  it("is keyboard-focusable and exposes role=button", () => {
    const el = createCulturalPoiMarkerElement({
      label: "Test",
      background: "#000",
      enriched: false,
      onClick: () => {},
    });
    expect(el.getAttribute("role")).toBe("button");
    expect(el.getAttribute("tabindex")).toBe("0");
  });
});
