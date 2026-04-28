/**
 * Marker icon registry — single source of truth for map markers and alert pictograms.
 *
 * Issue #390 — Design Foundations: unified pictogram system + visual legend.
 *
 * Each icon is a React component rendering a 24x24 SVG that uses `currentColor`,
 * so the consumer controls the colour via Tailwind utility classes (`text-...`)
 * or direct `style={{ color }}`.
 *
 * The registry exposes 12 high-level categories. Sub-types (e.g. `hotel` /
 * `motel`) all map onto the relevant category through {@link resolveCategory}.
 */
import type { ComponentType, SVGProps } from "react";

export type MarkerCategory =
  | "accommodation"
  | "water"
  | "supply"
  | "bike-workshop"
  | "railway-station"
  | "health"
  | "border-crossing"
  | "river-crossing"
  | "early-departure"
  | "cultural-poi"
  | "event"
  | "user-waypoint";

export type MarkerIconProps = SVGProps<SVGSVGElement> & {
  /** Pixel size — defaults to 24, the design-foundations standard. */
  size?: number;
  /** Optional accessible label. When provided, the SVG gets role="img". */
  title?: string;
};

const baseSvgProps = {
  width: 24,
  height: 24,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.75,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
} as const;

function withSize({
  size,
  title,
  ...rest
}: MarkerIconProps): SVGProps<SVGSVGElement> {
  const dimension = size ?? 24;
  const ariaProps = title
    ? ({ role: "img", "aria-label": title } as const)
    : ({ "aria-hidden": true, focusable: false } as const);
  // Strip the `title` text prop — it is rendered as a `<title>` child
  // by the icon components themselves; it must not propagate as an
  // attribute on the `<svg>` element.
  return {
    ...baseSvgProps,
    ...ariaProps,
    ...rest,
    width: dimension,
    height: dimension,
  };
}

// ── Icons ────────────────────────────────────────────────────────────────────

/** Hébergements — bed/roof shape (covers hotel, motel, hostel, guest_house, chalet…). */
export function AccommodationIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <path d="M3 19V8l9-5 9 5v11" />
      <path d="M3 13h18" />
      <path d="M7 19v-3h10v3" />
    </svg>
  );
}

/** Points d'eau — droplet. */
export function WaterIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <path d="M12 2.5c4 5 6.5 8.5 6.5 12a6.5 6.5 0 0 1-13 0c0-3.5 2.5-7 6.5-12Z" />
    </svg>
  );
}

/** Ravitaillement — shopping bag (épicerie / supermarché). */
export function SupplyIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <path d="M5 8h14l-1.2 11a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8L5 8Z" />
      <path d="M9 8V6a3 3 0 0 1 6 0v2" />
    </svg>
  );
}

/** Atelier vélo — wrench. */
export function BikeWorkshopIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <path d="M14.7 6.3a4 4 0 0 1 4 5.4l-9.4 9.4a2 2 0 1 1-2.8-2.8l9.4-9.4a4 4 0 0 1-1.2-2.6Z" />
      <circle cx="6.5" cy="6.5" r="2.5" />
    </svg>
  );
}

/** Gare SNCF — train silhouette. */
export function RailwayStationIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <rect x="5" y="3" width="14" height="14" rx="3" />
      <path d="M5 12h14" />
      <path d="M9 17l-2 4" />
      <path d="M15 17l2 4" />
      <circle cx="9" cy="14.5" r="0.75" fill="currentColor" stroke="none" />
      <circle cx="15" cy="14.5" r="0.75" fill="currentColor" stroke="none" />
    </svg>
  );
}

/** Pharmacies / hôpitaux — medical cross. */
export function HealthIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <rect x="3.5" y="3.5" width="17" height="17" rx="3" />
      <path d="M12 8v8" />
      <path d="M8 12h8" />
    </svg>
  );
}

/** Passage frontière — flag/post. */
export function BorderCrossingIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <path d="M5 21V3" />
      <path d="M5 4h11l-2 3.5 2 3.5H5" />
    </svg>
  );
}

/** Traversée d'un cours d'eau sans pont — wave with crossing arrow. */
export function RiverCrossingIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <path d="M3 16c2 0 2-2 4.5-2s2.5 2 4.5 2 2.5-2 4.5-2 2.5 2 4.5 2" />
      <path d="M3 20c2 0 2-2 4.5-2s2.5 2 4.5 2 2.5-2 4.5-2 2.5 2 4.5 2" />
      <path d="M12 3v8" />
      <path d="m9 8 3 3 3-3" />
    </svg>
  );
}

/** Départ avant l'aube — moon-with-star. */
export function EarlyDepartureIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <path d="M20 14.5A8 8 0 0 1 9.5 4a7 7 0 1 0 10.5 10.5Z" />
      <path d="M16.5 4.5l.7 1.6 1.6.7-1.6.7-.7 1.6-.7-1.6-1.6-.7 1.6-.7Z" />
    </svg>
  );
}

/** POI culturel — pillar / monument. */
export function CulturalPoiIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <path d="M3 21h18" />
      <path d="M5 21V10" />
      <path d="M9 21V10" />
      <path d="M15 21V10" />
      <path d="M19 21V10" />
      <path d="M3 10h18" />
      <path d="M12 3 4 7v3h16V7l-8-4Z" />
    </svg>
  );
}

/**
 * Cultural POI with rich metadata badge (description / openingHours / price).
 * Renders the base monument plus a small accent dot in the bottom-right
 * corner — used by callers that want to surface "more info available".
 */
export function CulturalPoiEnrichedIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <path d="M3 21h18" />
      <path d="M5 21V10" />
      <path d="M9 21V10" />
      <path d="M15 21V10" />
      <path d="M19 21V10" />
      <path d="M3 10h18" />
      <path d="M12 3 4 7v3h16V7l-8-4Z" />
      <circle cx="20" cy="20" r="3" fill="currentColor" stroke="none" />
      <text
        x="20"
        y="21.4"
        textAnchor="middle"
        fontSize="4.2"
        fontWeight="700"
        fill="white"
        stroke="none"
      >
        i
      </text>
    </svg>
  );
}

/** Événement daté — calendar with mark. */
export function EventIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <rect x="3.5" y="5" width="17" height="15" rx="2" />
      <path d="M3.5 10h17" />
      <path d="M8 3v4" />
      <path d="M16 3v4" />
      <circle cx="12" cy="15" r="1.6" fill="currentColor" stroke="none" />
    </svg>
  );
}

/** Waypoint utilisateur — classic pin. */
export function UserWaypointIcon(props: MarkerIconProps) {
  const svgProps = withSize(props);
  return (
    <svg {...svgProps}>
      {props.title ? <title>{props.title}</title> : null}
      <path d="M12 22s7-7.4 7-12.5A7 7 0 1 0 5 9.5C5 14.6 12 22 12 22Z" />
      <circle cx="12" cy="9.5" r="2.5" />
    </svg>
  );
}

// ── Registry ─────────────────────────────────────────────────────────────────

export const MarkerIcon: Record<
  MarkerCategory,
  ComponentType<MarkerIconProps>
> = {
  accommodation: AccommodationIcon,
  water: WaterIcon,
  supply: SupplyIcon,
  "bike-workshop": BikeWorkshopIcon,
  "railway-station": RailwayStationIcon,
  health: HealthIcon,
  "border-crossing": BorderCrossingIcon,
  "river-crossing": RiverCrossingIcon,
  "early-departure": EarlyDepartureIcon,
  "cultural-poi": CulturalPoiIcon,
  event: EventIcon,
  "user-waypoint": UserWaypointIcon,
};

// ── Sub-type → category mapping ──────────────────────────────────────────────

/** Accommodation sub-types covered by the unified registry. */
export const ACCOMMODATION_SUBTYPES = [
  "hotel",
  "motel",
  "guest_house",
  "chalet",
  "hostel",
  "alpine_hut",
  "camp_site",
  "wilderness_hut",
  "shelter",
] as const;

export type AccommodationSubtype = (typeof ACCOMMODATION_SUBTYPES)[number];

/** Returns true if `value` matches a known accommodation sub-type. */
export function isAccommodationSubtype(
  value: string,
): value is AccommodationSubtype {
  return (ACCOMMODATION_SUBTYPES as readonly string[]).includes(value);
}

/**
 * Resolves an alert source / accommodation type / generic identifier into
 * one of the 12 marker categories. Returns `null` when no mapping applies.
 */
export function resolveCategory(
  identifier: string | null | undefined,
): MarkerCategory | null {
  if (!identifier) return null;
  const id = identifier.toLowerCase();

  if (isAccommodationSubtype(id)) return "accommodation";

  switch (id) {
    case "water":
    case "drinking_water":
    case "water_point":
      return "water";
    case "supply":
    case "supplies":
    case "supermarket":
    case "convenience":
    case "grocery":
    case "food":
      return "supply";
    case "bike-workshop":
    case "bicycle_repair":
    case "bicycle":
    case "compressed_air":
      return "bike-workshop";
    case "railway":
    case "railway_station":
    case "train_station":
      return "railway-station";
    case "pharmacy":
    case "hospital":
    case "doctors":
    case "clinic":
    case "health":
      return "health";
    case "border":
    case "border_crossing":
    case "country_border":
      return "border-crossing";
    case "river_crossing":
    case "ford":
    case "water_crossing":
      return "river-crossing";
    case "early_departure":
    case "sunset_alert":
    case "wakeup":
      return "early-departure";
    case "cultural_poi":
    case "datatourisme":
    case "wikidata":
    case "monument":
    case "museum":
      return "cultural-poi";
    case "event":
    case "festival":
    case "market":
    case "public_holiday":
      return "event";
    case "waypoint":
    case "user_waypoint":
    case "user":
      return "user-waypoint";
    default:
      return null;
  }
}

/**
 * Default colour token (CSS variable) per category, expressed as Tailwind
 * arbitrary-value classes. Consumers can override at the call-site.
 */
export const MARKER_CATEGORY_COLOR: Record<MarkerCategory, string> = {
  accommodation: "text-[#7c3aed]",
  water: "text-[#0284c7]",
  supply: "text-[#15803d]",
  "bike-workshop": "text-[#475569]",
  "railway-station": "text-[#1d4ed8]",
  health: "text-[#dc2626]",
  "border-crossing": "text-[#a16207]",
  "river-crossing": "text-[#0e7490]",
  "early-departure": "text-[#7c3aed]",
  "cultural-poi": "text-[#b45309]",
  event: "text-[#c2410c]",
  "user-waypoint": "text-[var(--brand)]",
};

/** Ordered list of categories (used by the legend component). */
export const MARKER_CATEGORIES: readonly MarkerCategory[] = [
  "accommodation",
  "water",
  "supply",
  "bike-workshop",
  "railway-station",
  "health",
  "border-crossing",
  "river-crossing",
  "early-departure",
  "cultural-poi",
  "event",
  "user-waypoint",
] as const;
