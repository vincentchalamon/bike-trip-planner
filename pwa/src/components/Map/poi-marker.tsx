/**
 * Cultural POI map marker — extends the unified registry marker with a
 * pulsating halo and a click handler that opens the rich popover.
 *
 * The pulsation is purely CSS (see `map-markers.css`,
 * `@keyframes map-marker-pulse`) and is automatically disabled for users
 * preferring reduced motion. It applies only to cultural POI markers — the
 * other categories keep their static look so the halo signals "tap me for
 * extra info" without competing visually.
 *
 * Issue #398 — sprint 26: enriched cultural POI popover.
 */
import { createCategoryMarkerElement } from "./icons/markerDom";

export interface CulturalPoiMarkerOptions {
  /** Accessible label (sets aria-label + role=img on the marker). */
  label: string;
  /** Background colour of the disc — keep severity-aware. */
  background: string;
  /** Outer disc diameter in pixels. */
  size?: number;
  /** Whether the POI carries enriched metadata (Wikidata / DataTourisme). */
  enriched: boolean;
  /** Click handler — opens the popover. */
  onClick: () => void;
}

/**
 * Creates a DOM element for a cultural POI marker. The element wraps the
 * standard category icon with:
 *
 *   - a pulsating halo (`<span class="map-marker__pulse">`)
 *   - the "i" badge in the bottom-right corner when enriched
 *   - a `data-enriched` attribute consumed by E2E tests
 *
 * Click and keyboard activation are wired so the marker behaves like a
 * regular button (Enter / Space).
 */
export function createCulturalPoiMarkerElement({
  label,
  background,
  size = 24,
  enriched,
  onClick,
}: CulturalPoiMarkerOptions): HTMLElement {
  const el = createCategoryMarkerElement("cultural-poi", {
    label,
    background,
    size,
    extraClass: `map-marker--cultural-poi map-marker--alert map-marker--alert-warning${
      enriched ? " map-marker--cultural-enriched" : ""
    }`,
  });

  el.setAttribute("role", "button");
  el.setAttribute("tabindex", "0");
  el.dataset.testid = "cultural-poi-marker";
  el.dataset.enriched = enriched ? "true" : "false";

  // Pulsating halo — keeps layout simple by stacking absolutely behind the SVG.
  const pulse = document.createElement("span");
  pulse.className = "map-marker__pulse";
  pulse.setAttribute("aria-hidden", "true");
  el.insertBefore(pulse, el.firstChild);

  const handleActivate = (event: Event) => {
    event.preventDefault();
    event.stopPropagation();
    onClick();
  };

  el.addEventListener("click", handleActivate);
  el.addEventListener("keydown", (event) => {
    if (event.key === "Enter" || event.key === " ") {
      handleActivate(event);
    }
  });

  return el;
}
