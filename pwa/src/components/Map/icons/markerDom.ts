/**
 * DOM-side helpers to create map markers using the centralised
 * {@link MarkerCategory} registry — without ever resorting to innerHTML.
 *
 * MapLibre / Leaflet expect plain HTMLElements; we build them with
 * `document.createElementNS` so the SVG namespace is correct, while still
 * deriving each path/shape from the same source of truth as the React
 * components in `./index.tsx`.
 */
import type { MarkerCategory } from "./index";

const SVG_NS = "http://www.w3.org/2000/svg";

interface SvgShape {
  /** Element name (`path`, `rect`, `circle`…). */
  tag: keyof SVGElementTagNameMap | string;
  /** Attributes — values are stringified before being set. */
  attrs: Record<string, string | number>;
  /** Optional text content (only used for `<text>` nodes). */
  text?: string;
  /** Optional child shapes. */
  children?: SvgShape[];
}

/**
 * Vector definitions per category. They mirror the JSX in `./index.tsx` 1:1
 * but as a serialisable structure so we can render them into the DOM
 * via `createElementNS` only.
 */
const ICON_SHAPES: Record<MarkerCategory, SvgShape[]> = {
  accommodation: [
    { tag: "path", attrs: { d: "M3 19V8l9-5 9 5v11" } },
    { tag: "path", attrs: { d: "M3 13h18" } },
    { tag: "path", attrs: { d: "M7 19v-3h10v3" } },
  ],
  water: [
    {
      tag: "path",
      attrs: {
        d: "M12 2.5c4 5 6.5 8.5 6.5 12a6.5 6.5 0 0 1-13 0c0-3.5 2.5-7 6.5-12Z",
      },
    },
  ],
  supply: [
    {
      tag: "path",
      attrs: {
        d: "M5 8h14l-1.2 11a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8L5 8Z",
      },
    },
    { tag: "path", attrs: { d: "M9 8V6a3 3 0 0 1 6 0v2" } },
  ],
  "bike-workshop": [
    {
      tag: "path",
      attrs: {
        d: "M14.7 6.3a4 4 0 0 1 4 5.4l-9.4 9.4a2 2 0 1 1-2.8-2.8l9.4-9.4a4 4 0 0 1-1.2-2.6Z",
      },
    },
    { tag: "circle", attrs: { cx: 6.5, cy: 6.5, r: 2.5 } },
  ],
  "railway-station": [
    { tag: "rect", attrs: { x: 5, y: 3, width: 14, height: 14, rx: 3 } },
    { tag: "path", attrs: { d: "M5 12h14" } },
    { tag: "path", attrs: { d: "M9 17l-2 4" } },
    { tag: "path", attrs: { d: "M15 17l2 4" } },
    {
      tag: "circle",
      attrs: {
        cx: 9,
        cy: 14.5,
        r: 0.75,
        fill: "currentColor",
        stroke: "none",
      },
    },
    {
      tag: "circle",
      attrs: {
        cx: 15,
        cy: 14.5,
        r: 0.75,
        fill: "currentColor",
        stroke: "none",
      },
    },
  ],
  health: [
    { tag: "rect", attrs: { x: 3.5, y: 3.5, width: 17, height: 17, rx: 3 } },
    { tag: "path", attrs: { d: "M12 8v8" } },
    { tag: "path", attrs: { d: "M8 12h8" } },
  ],
  "border-crossing": [
    { tag: "path", attrs: { d: "M5 21V3" } },
    { tag: "path", attrs: { d: "M5 4h11l-2 3.5 2 3.5H5" } },
  ],
  "river-crossing": [
    {
      tag: "path",
      attrs: {
        d: "M3 16c2 0 2-2 4.5-2s2.5 2 4.5 2 2.5-2 4.5-2 2.5 2 4.5 2",
      },
    },
    {
      tag: "path",
      attrs: {
        d: "M3 20c2 0 2-2 4.5-2s2.5 2 4.5 2 2.5-2 4.5-2 2.5 2 4.5 2",
      },
    },
    { tag: "path", attrs: { d: "M12 3v8" } },
    { tag: "path", attrs: { d: "m9 8 3 3 3-3" } },
  ],
  "early-departure": [
    {
      tag: "path",
      attrs: {
        d: "M20 14.5A8 8 0 0 1 9.5 4a7 7 0 1 0 10.5 10.5Z",
      },
    },
    {
      tag: "path",
      attrs: {
        d: "M16.5 4.5l.7 1.6 1.6.7-1.6.7-.7 1.6-.7-1.6-1.6-.7 1.6-.7Z",
      },
    },
  ],
  "cultural-poi": [
    { tag: "path", attrs: { d: "M3 21h18" } },
    { tag: "path", attrs: { d: "M5 21V10" } },
    { tag: "path", attrs: { d: "M9 21V10" } },
    { tag: "path", attrs: { d: "M15 21V10" } },
    { tag: "path", attrs: { d: "M19 21V10" } },
    { tag: "path", attrs: { d: "M3 10h18" } },
    { tag: "path", attrs: { d: "M12 3 4 7v3h16V7l-8-4Z" } },
  ],
  event: [
    { tag: "rect", attrs: { x: 3.5, y: 5, width: 17, height: 15, rx: 2 } },
    { tag: "path", attrs: { d: "M3.5 10h17" } },
    { tag: "path", attrs: { d: "M8 3v4" } },
    { tag: "path", attrs: { d: "M16 3v4" } },
    {
      tag: "circle",
      attrs: { cx: 12, cy: 15, r: 1.6, fill: "currentColor", stroke: "none" },
    },
  ],
  "user-waypoint": [
    {
      tag: "path",
      attrs: {
        d: "M12 22s7-7.4 7-12.5A7 7 0 1 0 5 9.5C5 14.6 12 22 12 22Z",
      },
    },
    { tag: "circle", attrs: { cx: 12, cy: 9.5, r: 2.5 } },
  ],
};

function appendShapes(parent: SVGElement, shapes: readonly SvgShape[]): void {
  for (const shape of shapes) {
    const el = document.createElementNS(SVG_NS, shape.tag);
    for (const [key, value] of Object.entries(shape.attrs)) {
      el.setAttribute(key, String(value));
    }
    if (shape.text) {
      el.textContent = shape.text;
    }
    if (shape.children) {
      appendShapes(el as SVGElement, shape.children);
    }
    parent.appendChild(el);
  }
}

interface CreateMarkerSvgOptions {
  size?: number;
  /** Optional accessible label rendered as `<title>`. */
  title?: string;
}

/**
 * Creates a standalone SVG element (24x24 viewBox by default) for the given
 * marker category — safe to insert into the DOM via `appendChild`.
 */
export function createMarkerSvg(
  category: MarkerCategory,
  { size = 24, title }: CreateMarkerSvgOptions = {},
): SVGElement {
  const svg = document.createElementNS(SVG_NS, "svg");
  svg.setAttribute("width", String(size));
  svg.setAttribute("height", String(size));
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("fill", "none");
  svg.setAttribute("stroke", "currentColor");
  svg.setAttribute("stroke-width", "1.75");
  svg.setAttribute("stroke-linecap", "round");
  svg.setAttribute("stroke-linejoin", "round");
  svg.setAttribute("aria-hidden", title ? "false" : "true");
  svg.setAttribute("focusable", "false");

  if (title) {
    svg.setAttribute("role", "img");
    const titleEl = document.createElementNS(SVG_NS, "title");
    titleEl.textContent = title;
    svg.appendChild(titleEl);
  }

  appendShapes(svg, ICON_SHAPES[category]);
  return svg;
}

/**
 * Creates a fully-styled marker element for MapLibre / Leaflet: a circular
 * coloured background containing the SVG icon for the requested category.
 *
 * @param category Marker category to render.
 * @param options.label Accessible label (sets aria-label + role=img).
 * @param options.background CSS background colour for the disc.
 * @param options.iconColor CSS colour applied to the SVG strokes.
 * @param options.size Outer disc diameter in pixels (defaults to 24).
 * @param options.extraClass Optional extra CSS classes.
 */
export function createCategoryMarkerElement(
  category: MarkerCategory,
  {
    label,
    background,
    iconColor = "#fff",
    size = 24,
    extraClass,
  }: {
    label: string;
    background: string;
    iconColor?: string;
    size?: number;
    extraClass?: string;
  },
): HTMLElement {
  const el = document.createElement("div");
  el.className = `map-marker map-marker--icon${extraClass ? ` ${extraClass}` : ""}`;
  el.setAttribute("aria-label", label);
  el.setAttribute("role", "img");
  el.style.width = `${size}px`;
  el.style.height = `${size}px`;
  el.style.background = background;
  el.style.color = iconColor;
  el.dataset.category = category;

  const svg = createMarkerSvg(category, { size: Math.round(size * 0.6) });
  el.appendChild(svg);
  return el;
}
