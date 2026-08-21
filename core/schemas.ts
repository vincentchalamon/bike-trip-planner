import { z } from "zod";
import { DEFAULT_ACCOMMODATION_RADIUS_KM } from "./accommodation-constants";

export const CoordinateSchema = z.object({
  lat: z.number(),
  lon: z.number(),
  ele: z.number().default(0),
});

// A single highlighted road stretch: an ordered list of [lat, lon] points.
export const AlertSegmentSchema = z.array(z.tuple([z.number(), z.number()]));

export const AlertActionSchema = z.object({
  kind: z.enum(["auto_fix", "detour", "navigate", "dismiss"]),
  label: z.string(),
  // `segments` carries the geometry of the concerned road stretch for the
  // internal-map highlight (issue #982); other keys (lat/lon) stay loose.
  payload: z
    .looseObject({
      lat: z.number().optional(),
      lon: z.number().optional(),
      segments: z.array(AlertSegmentSchema).optional(),
    })
    .optional()
    .default({}),
});

export const AlertSchema = z.object({
  // Stable rule-variant identifier (backend `App\Enum\AlertCode`). Absent/null on
  // alerts persisted before it existed, hence the fallback in `alertKey`.
  code: z.string().nullable().optional(),
  type: z.enum(["critical", "warning", "nudge"]),
  message: z.string(),
  lat: z.number().nullable().optional(),
  lon: z.number().nullable().optional(),
  source: z.string().optional(),
  // Cultural POI alert extra fields
  poiName: z.string().optional(),
  poiType: z.string().optional(),
  poiLat: z.number().optional(),
  poiLon: z.number().optional(),
  distanceFromRoute: z.number().optional(),
  openingHours: z.string().optional(),
  estimatedPrice: z.number().optional(),
  description: z.string().optional(),
  wikidataId: z.string().optional(),
  imageUrl: z.string().url().optional().catch(undefined),
  wikipediaUrl: z.string().url().optional().catch(undefined),
  // OSM identity of the entry: `null` on a DataTourisme one, so the "see on OSM"
  // link only renders when both are set.
  osmType: z
    .enum(["node", "way", "relation"])
    .nullable()
    .optional()
    .catch(null),
  osmId: z.number().int().nullable().optional().catch(null),
  // Optional contextual action
  action: AlertActionSchema.nullable().optional(),
});

export const WeatherForecastSchema = z.object({
  icon: z.string(),
  description: z.string(),
  tempMin: z.number(),
  tempMax: z.number(),
  windSpeed: z.number(),
  windDirection: z.string(),
  precipitationProbability: z.number(),
  humidity: z.number().int().min(0).max(100).default(50),
  comfortIndex: z.number().int().min(0).max(100).default(100),
  relativeWindDirection: z
    .enum(["headwind", "tailwind", "crosswind", "unknown"])
    .default("unknown"),
});

export const PointOfInterestSchema = z.object({
  name: z.string(),
  category: z.string(),
  lat: z.number(),
  lon: z.number(),
  distanceFromStart: z.number().nullable().optional(),
  // OSM identity of the entry: `null` on a DataTourisme one, so the "see on OSM"
  // link only renders when both are set.
  osmType: z
    .enum(["node", "way", "relation"])
    .nullable()
    .optional()
    .catch(null),
  osmId: z.number().int().nullable().optional().catch(null),
});

// Curated resupply suggestions per stage (#1099), replacing the raw POI dump:
// 2 food shops near the lunch stop, one water point each half of the day, 2 food
// shops at the arrival. All fields default so a legacy/absent value parses empty.
export const ResupplySchema = z.object({
  foodAtLunch: z.array(PointOfInterestSchema).default([]),
  waterMorning: PointOfInterestSchema.nullable().default(null),
  waterAfternoon: PointOfInterestSchema.nullable().default(null),
  foodAtArrival: z.array(PointOfInterestSchema).default([]),
});

export const SupplyWaterPointSchema = z.object({
  name: z.string().nullable(),
  lat: z.number(),
  lon: z.number(),
  distanceFromStart: z.number(),
});

export const SupplyFoodPointSchema = z.object({
  name: z.string().nullable(),
  category: z.string(),
  lat: z.number(),
  lon: z.number(),
  distanceFromStart: z.number(),
});

export const SupplyMarkerSchema = z.object({
  type: z.enum(["water", "food", "both"]),
  distanceFromStart: z.number(),
  lat: z.number(),
  lon: z.number(),
  water: z.array(SupplyWaterPointSchema),
  food: z.array(SupplyFoodPointSchema),
});

export const EventSchema = z.object({
  name: z.string(),
  type: z.string(),
  lat: z.number(),
  lon: z.number(),
  startDate: z.string(),
  endDate: z.string(),
  url: z.string().url().nullable().optional().catch(null),
  description: z.string().nullable().optional(),
  priceMin: z.number().nullable().optional(),
  distanceToEndPoint: z.number().default(0),
  source: z.string().default("datatourisme"),
  wikidataId: z.string().nullable().optional(),
  imageUrl: z.string().url().nullable().optional().catch(null),
  wikipediaUrl: z.string().url().nullable().optional().catch(null),
  openingHours: z.string().nullable().optional(),
});

export const AccommodationSchema = z.object({
  name: z.string(),
  type: z.string(),
  lat: z.number(),
  lon: z.number(),
  estimatedPriceMin: z.number(),
  estimatedPriceMax: z.number(),
  isExactPrice: z.boolean(),
  url: z.string().url().nullable().optional().catch(null),
  possibleClosed: z.boolean().default(false),
  distanceToEndPoint: z.number().default(0),
  // "manual" flags an accommodation the rider entered by hand (hors-app); it is a
  // first-class Accommodation otherwise indistinguishable downstream (ADR-055).
  source: z.enum(["osm", "datatourisme", "manual"]).default("osm"),
  description: z.string().nullable().optional(),
  imageUrl: z.string().url().nullable().optional().catch(null),
  wikipediaUrl: z.string().url().nullable().optional().catch(null),
  openingHours: z.string().nullable().optional(),
  phone: z.string().nullable().optional(),
  // General postal address (OSM addr:*, DataTourisme, or the rider's input for a
  // manual entry). Null when the source carries none.
  address: z.string().nullable().optional(),
  // OSM identity of the entry: `null` on a DataTourisme one, and on anything the
  // rider added by hand, so the "see on OSM" link only renders when both are set.
  osmType: z
    .enum(["node", "way", "relation"])
    .nullable()
    .optional()
    .catch(null),
  osmId: z.number().int().nullable().optional().catch(null),
});

/**
 * Single surface segment in a stage's surface breakdown — pairs an OSM-style
 * `surface` tag (e.g. `paved`, `gravel`, `cobblestone`, `unknown`) with its
 * cumulative length in metres along the route. The frontend converts the list
 * to percentages on the fly.
 *
 * NOTE: backend (`StageResponse`) does not yet emit this field. The schema is
 * forward-compatible so the SurfaceBreakdown component renders automatically
 * when the field appears, without requiring a frontend release.
 * TODO(#403 backend follow-up): wire via typegen once the OSM aggregation ships.
 */
export const SurfaceSegmentSchema = z.object({
  surface: z.string(),
  lengthMeters: z.number().nonnegative(),
});

export const StageDataSchema = z.object({
  dayNumber: z.number(),
  distance: z.number(),
  elevation: z.number(),
  elevationLoss: z.number().default(0),
  startPoint: CoordinateSchema,
  endPoint: CoordinateSchema,
  geometry: z.array(CoordinateSchema),
  label: z.string().nullable(),
  startLabel: z.string().nullable(),
  endLabel: z.string().nullable(),
  weather: WeatherForecastSchema.nullable(),
  alerts: z.array(AlertSchema),
  resupply: ResupplySchema.default({
    foodAtLunch: [],
    waterMorning: null,
    waterAfternoon: null,
    foodAtArrival: [],
  }),
  accommodations: z.array(AccommodationSchema),
  selectedAccommodation: AccommodationSchema.nullable().optional(),
  accommodationSearchRadiusKm: z
    .number()
    .int()
    .positive()
    .default(DEFAULT_ACCOMMODATION_RADIUS_KM),
  isRestDay: z.boolean().default(false),
  /**
   * Fraction (0..1) of the stage that follows a signed cycle route (ADR-040).
   * Optional/forward-compatible: present on the persisted trip detail, absent
   * from the live SSE payloads (treated as 0).
   */
  onCycleNetwork: z.number().min(0).max(1).optional(),
  supplyTimeline: z.array(SupplyMarkerSchema).default([]),
  events: z.array(EventSchema).default([]),
  /**
   * Optional per-stage surface breakdown — list of `{ surface, lengthMeters }`
   * pairs aggregated from OSM `surface` tags along the stage. Rendered as a
   * stacked bar in the stage detail panel. Schema is forward-compatible:
   * backend may emit this field when available without breaking existing
   * clients.
   * TODO(#403): wire via typegen once backend StageResponse DTO ships
   * `surfaceBreakdown`.
   */
  surfaceBreakdown: z.array(SurfaceSegmentSchema).nullable().optional(),
});

export const TripStateSchema = z.object({
  trip: z
    .object({
      id: z.string(),
      title: z.string(),
      sourceUrl: z.string(),
    })
    .nullable(),
  totalDistance: z.number().nullable(),
  totalElevation: z.number().nullable(),
  sourceType: z.string().nullable(),
  startDate: z.string().nullable(),
  endDate: z.string().nullable(),
  fatigueFactor: z.number().min(0.5).max(1.0).default(0.9),
  elevationPenalty: z.number().positive().default(50),
  ebikeMode: z.boolean().default(false),
  departureHour: z.number().int().min(0).max(23).default(8),
  stages: z.array(StageDataSchema),
  computationStatus: z.record(z.string(), z.string()),
});

export type CoordinateData = z.infer<typeof CoordinateSchema>;
export type AlertActionData = z.infer<typeof AlertActionSchema>;
export type AlertData = z.infer<typeof AlertSchema>;
export type WeatherData = z.infer<typeof WeatherForecastSchema>;
export type PoiData = z.infer<typeof PointOfInterestSchema>;
export type ResupplyData = z.infer<typeof ResupplySchema>;

/** A stage with no resupply suggestions (recompute reset / before the scan). */
export const EMPTY_RESUPPLY: ResupplyData = {
  foodAtLunch: [],
  waterMorning: null,
  waterAfternoon: null,
  foodAtArrival: [],
};

/** Flatten a resupply into a POI list (map markers, GPX waypoints), deduped by coordinate. */
export function resupplyPois(resupply: ResupplyData): PoiData[] {
  const seen = new Set<string>();
  const out: PoiData[] = [];
  for (const poi of [
    ...resupply.foodAtLunch,
    resupply.waterMorning,
    resupply.waterAfternoon,
    ...resupply.foodAtArrival,
  ]) {
    if (!poi) continue;
    const key = `${poi.lat},${poi.lon}`;
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(poi);
  }
  return out;
}
export type SupplyWaterPointData = z.infer<typeof SupplyWaterPointSchema>;
export type SupplyFoodPointData = z.infer<typeof SupplyFoodPointSchema>;
export type SupplyMarkerData = z.infer<typeof SupplyMarkerSchema>;
export type EventData = z.infer<typeof EventSchema>;
export type AccommodationData = z.infer<typeof AccommodationSchema>;
export type SurfaceSegmentData = z.infer<typeof SurfaceSegmentSchema>;
export type StageData = z.infer<typeof StageDataSchema>;
