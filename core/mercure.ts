import type { components } from "./schema";

// Mercure SSE wire payloads shared by web and mobile. Pure type declarations
// (no runtime), mirrored from the backend publishers (StagePayloadMapper et al.).
// Moved out of pwa/src/lib/mercure/types.ts into core/ so the mobile thin store
// consumes the same event contract (#1013).

export interface CoordinatePayload {
  lat: number;
  lon: number;
  ele: number;
}

export interface StagePayload {
  stageId: string;
  dayNumber: number;
  distance: number;
  elevation: number;
  elevationLoss: number;
  startPoint: CoordinatePayload;
  endPoint: CoordinatePayload;
  geometry: CoordinatePayload[];
  label: string | null;
  isRestDay?: boolean;
}

/**
 * Fully enriched stage payload carried by Mode 1 `trip_ready` and Mode 2
 * `stage_updated` events. Mirrors {@link StagePayloadMapper::toPayload} on
 * the backend — keep both in sync.
 */
export interface EnrichedStagePayload extends StagePayload {
  weather: WeatherPayload["weather"];
  alerts: AlertPayload[];
  resupply: ResupplyPayload;
  accommodations: AccommodationPayload[];
  selectedAccommodation: AccommodationPayload | null;
  events: EventPayload[];
}

export interface HourlyWeatherSlotPayload {
  hour: number;
  temp: number;
  apparentTemp: number;
  precipitationMm: number;
  precipitationProbability: number;
  windSpeed: number;
  windGusts: number;
  windDirectionDeg: number;
  relativeWindDirection: "headwind" | "tailwind" | "crosswind" | "unknown";
  weatherCode: number;
}

export interface WeatherPayload {
  dayNumber: number;
  weather: {
    icon: string;
    description: string;
    tempMin: number;
    tempMax: number;
    windSpeed: number;
    windDirection: string;
    precipitationProbability: number;
    humidity: number;
    comfortIndex: number;
    relativeWindDirection: "headwind" | "tailwind" | "crosswind" | "unknown";
    apparentTempMin: number;
    apparentTempMax: number;
    windGusts: number;
    precipitationMm: number;
    uvIndex: number;
    hourly: HourlyWeatherSlotPayload[];
  } | null;
}

/**
 * Every alert producer, mirrored from `App\Enum\AlertGroup`.
 *
 * Not trusted to stay in step by hand: the server enumerates the same list into the OpenAPI
 * schema, and {@link ALERT_GROUPS_MATCH_THE_SCHEMA} below fails the build the moment the two
 * diverge. That is why no cross-language drift test guards this list.
 */
export type AlertGroup =
  | "terrain"
  | "pois"
  | "accommodations"
  | "calendar"
  | "wind"
  | "bike_shop"
  | "water_point"
  | "health_service"
  | "cultural_poi"
  | "railway_station"
  | "border_crossing"
  | "ferry"
  | "ford";

export interface AlertActionPayload {
  kind: "auto_fix" | "detour" | "navigate" | "dismiss";
  /**
   * Catalogue key of the label; what the server stores (ADR-069).
   *
   * Optional for the same reason `group` is: a payload built before the key travelled may
   * still be in flight, and no client needs it — `label` is already rendered.
   */
  labelKey?: string;
  /** The label itself, rendered by the server in the reader's language. */
  label: string;
  payload: Record<string, unknown>;
}

export interface AlertPayload {
  /**
   * The producer that owns this alert, and the unit in which alerts are replaced (ADR-068).
   *
   * Sent by the server on every alert, live and persisted alike. Optional only because a
   * live payload built before the group travelled may still be in flight.
   */
  group?: AlertGroup;
  /** Stable rule-variant identifier (backend `App\Enum\AlertCode`); null on legacy persisted alerts. */
  code?: string | null;
  type: "critical" | "warning" | "nudge";
  /**
   * The sentence, rendered by the server in the reader's language (ADR-069).
   *
   * Derived from `messageKey` and `parameters` at read time, not stored: the same row reads
   * French to one account and English to the next, with nothing recomputed.
   */
  message: string;
  /** Catalogue key the message was rendered from. */
  messageKey?: string;
  /**
   * The message arguments, raw and unformatted — a distance in metres, a gradient in
   * percent. Kept next to the sentence so a client can phrase its own.
   */
  parameters?: Record<string, string | number | string[]>;
  lat: number | null;
  lon: number | null;
  source?: string;
  action?: AlertActionPayload | null;
}

export interface PoiPayload {
  name: string;
  category: string;
  lat: number;
  lon: number;
  distanceFromStart: number | null;
  osmType?: "node" | "way" | "relation" | null;
  osmId?: number | null;
}

// Curated resupply suggestions per stage (#1099), replacing the raw POI list.
export interface ResupplyPayload {
  foodAtLunch: PoiPayload[];
  waterMorning: PoiPayload | null;
  waterAfternoon: PoiPayload | null;
  foodAtArrival: PoiPayload[];
}

export interface AccommodationPayload {
  name: string;
  type: string;
  lat: number;
  lon: number;
  estimatedPriceMin: number;
  estimatedPriceMax: number;
  isExactPrice: boolean;
  possibleClosed: boolean;
  distanceToEndPoint: number;
  source: "osm" | "datatourisme" | "manual";
  url?: string | null;
  description?: string | null;
  imageUrl?: string | null;
  wikipediaUrl?: string | null;
  openingHours?: string | null;
  phone?: string | null;
  address?: string | null;
  osmType?: "node" | "way" | "relation" | null;
  osmId?: number | null;
}

export interface EventPayload {
  name: string;
  type: string;
  lat: number;
  lon: number;
  startDate: string;
  endDate: string;
  url: string | null;
  description: string | null;
  priceMin: number | null;
  distanceToEndPoint: number;
  source: string;
  wikidataId: string | null;
  imageUrl?: string | null;
  wikipediaUrl?: string | null;
  openingHours?: string | null;
}

export interface SupplyWaterPoint {
  name: string | null;
  lat: number;
  lon: number;
  distanceFromStart: number;
}

export interface SupplyFoodPoint {
  name: string | null;
  category: string;
  lat: number;
  lon: number;
  distanceFromStart: number;
}

export interface SupplyMarker {
  type: "water" | "food" | "both";
  distanceFromStart: number;
  lat: number;
  lon: number;
  water: SupplyWaterPoint[];
  food: SupplyFoodPoint[];
}

export type MercureEvent =
  | {
      type: "route_parsed";
      data: {
        totalDistance: number;
        totalElevation: number;
        totalElevationLoss: number;
        sourceType: string;
        title: string | null;
      };
    }
  | {
      type: "stages_computed";
      data: { stages: StagePayload[]; affectedStageIds?: string[] };
    }
  | { type: "weather_fetched"; data: { stages: WeatherPayload[] } }
  | {
      type: "pois_scanned";
      data: {
        stageId: string;
        resupply: ResupplyPayload;
        alerts?: AlertPayload[];
      };
    }
  | {
      type: "accommodations_found";
      data: {
        stageId: string;
        accommodations: AccommodationPayload[];
        alerts?: AlertPayload[];
        searchRadiusKm?: number;
      };
    }
  | {
      type: "terrain_alerts";
      data: { alertsByStage: Record<string, AlertPayload[]> };
    }
  | {
      type: "calendar_alerts";
      data: {
        alerts: {
          stageId: string;
          dayNumber: number;
          code: string;
          type: string;
          message: string;
        }[];
      };
    }
  | {
      type: "wind_alerts";
      data: {
        alerts: (AlertPayload & { stageId: string; dayNumber: number })[];
      };
    }
  | {
      type: "bike_shop_alerts";
      data: {
        alerts: {
          stageId: string;
          code: string;
          type: string;
          message: string;
          dayNumber: number;
        }[];
      };
    }
  | {
      type: "water_point_alerts";
      data: {
        alerts: {
          stageId: string;
          code: string;
          type: string;
          message: string;
          dayNumber: number;
        }[];
        waterPointsByStage: {
          stageId: string;
          waterPoints: {
            lat: number;
            lon: number;
            distanceFromStart: number;
          }[];
        }[];
      };
    }
  | {
      type: "supply_timeline";
      data: {
        stageId: string;
        markers: SupplyMarker[];
      };
    }
  | {
      type: "health_service_alerts";
      data: {
        alerts: {
          stageId: string;
          dayNumber: number;
          code: string;
          type: string;
          message: string;
        }[];
      };
    }
  | {
      type: "cultural_poi_alerts";
      data: {
        alerts: {
          stageId: string;
          dayNumber: number;
          code: string;
          type: string;
          message: string;
          lat: number;
          lon: number;
          poiName: string;
          poiType: string;
          poiLat: number;
          poiLon: number;
          distanceFromRoute: number;
          openingHours?: string;
          estimatedPrice?: number;
          description?: string;
          wikidataId?: string;
          source?: string;
          imageUrl?: string;
          wikipediaUrl?: string;
          osmType?: "node" | "way" | "relation";
          osmId?: number;
        }[];
      };
    }
  | {
      type: "railway_station_alerts";
      data: {
        alerts: {
          stageId: string;
          dayNumber: number;
          code: string;
          type: string;
          message: string;
          // Absent when no station was found anywhere along the trip.
          action?: {
            kind: "navigate";
            label: string;
            payload: { lat: number; lon: number };
          };
          lat?: number;
          lon?: number;
        }[];
      };
    }
  | {
      type: "border_crossing_alerts";
      data: {
        alerts: {
          stageId: string;
          dayNumber: number;
          code: string;
          type: "nudge";
          message: string;
          action: {
            kind: "navigate";
            label: string;
            payload: { lat: number; lon: number };
          };
          lat: number;
          lon: number;
        }[];
      };
    }
  | {
      type: "ferry_alerts";
      data: {
        alerts: {
          stageId: string;
          dayNumber: number;
          code: string;
          type: "warning";
          message: string;
          action: {
            kind: "navigate";
            label: string;
            payload: { lat: number; lon: number };
          };
          lat: number;
          lon: number;
        }[];
      };
    }
  | {
      type: "ford_alerts";
      data: {
        alerts: {
          stageId: string;
          dayNumber: number;
          code: string;
          type: "nudge" | "warning";
          message: string;
          action: {
            kind: "navigate";
            label: string;
            payload: { lat: number; lon: number };
          };
          lat: number;
          lon: number;
        }[];
      };
    }
  | {
      type: "route_segment_recalculated";
      data: {
        stageId: string;
        reason: string;
        distance: number;
        elevationGain: number;
        duration: number;
        coordinates: { lat: number; lon: number; ele: number }[];
      };
    }
  | {
      type: "events_found";
      data: {
        stageId: string;
        events: EventPayload[];
      };
    }
  | { type: "validation_error"; data: { code: string; message: string } }
  | {
      type: "computation_error";
      data: { computation: string; message: string; retryable: boolean };
    }
  | {
      // Signal, not data: the trip moved and these computations were abandoned before they
      // settled (ADR-073). Not an error — nothing broke, nothing will be retried, and the
      // envelope's `version` is the one that superseded them. Published once per generation
      // bump with the whole list, so a client stops waiting on them in one go.
      //
      // `categories` is the granularity both clients render a per-block spinner at; it comes
      // from the server rather than being derived here, so `ComputationName::category()` is
      // not re-implemented in TypeScript.
      type: "computations_superseded";
      data: { computations: string[]; categories: string[] };
    }
  | {
      type: "trip_complete";
      data: { computationStatus: Record<string, string> };
    }
  | {
      // Mode 1 — Initial analysis progress tick emitted after each computation step.
      // Drives the progress bar without mutating stage data (UI-only payload).
      type: "computation_step_completed";
      data: {
        step: string;
        category:
          | "route"
          | "points_of_interest"
          | "accommodations"
          | "terrain_security"
          | "weather"
          | "context";
        completed: number;
        total: number;
      };
    }
  | {
      // Mode 1 — Final event of the initial analysis. Carries the full enriched
      // trip payload so the frontend can swap the whole state atomically,
      // avoiding the progressive layout-shift seen with the legacy event stream.
      type: "trip_ready";
      data: {
        stages: EnrichedStagePayload[];
        computationStatus: Record<string, string>;
      };
    }
  | {
      // Mode 2 — Per-stage update emitted after an inline modification
      // (Act 3). The frontend mutates the single slice identified by
      // `stageId` without rebuilding the whole trip.
      //
      // `position` comes along because identity alone is not enough for a stage
      // the client has never seen: it cannot tell the trailing stage a distance
      // edit just split off (append) from an event of a superseded generation
      // (drop). The position disambiguates the two.
      type: "stage_updated";
      data: { stageId: string; position: number; stage: EnrichedStagePayload };
    };

/**
 * What actually arrives on the wire: an event plus the fields the publisher puts at the
 * envelope root.
 *
 * `version` is the trip's structural version after the change the event describes. A client
 * pins it with `If-Match` on its next edit, and without it a regeneration performed by a
 * worker — which moves the version with no HTTP response to say so — would leave the client
 * holding a version that no longer exists, refused on everything it tried next.
 *
 * Optional because the publisher omits it for a trip it can no longer read, and because a
 * client that does not edit has no use for it.
 */
export type MercureEnvelope = MercureEvent & {
  version?: number;
  /** End-to-end trace id (Caddy → Symfony → workers → Mercure). Not consumed by clients. */
  correlationId?: string;
};

/**
 * Canonical list of every Mercure SSE event `type`. Single source of truth
 * shared by web and mobile: the web hook (`use-mercure.ts`) dispatches on these,
 * the pure `reduceMercureEvent` reducer in `reconciliation.ts` reconciles them,
 * and the drift guard test (`pwa/src/store/mercure-reconciliation.test.ts`)
 * asserts the three stay in lockstep. Keep alphabetically loose but complete —
 * {@link MERCURE_EVENT_TYPES_ARE_EXHAUSTIVE} fails the build if a union variant
 * is added above without being listed here.
 */
export const MERCURE_EVENT_TYPES = [
  "route_parsed",
  "stages_computed",
  "weather_fetched",
  "pois_scanned",
  "accommodations_found",
  "events_found",
  "supply_timeline",
  "terrain_alerts",
  "calendar_alerts",
  "wind_alerts",
  "bike_shop_alerts",
  "water_point_alerts",
  "health_service_alerts",
  "cultural_poi_alerts",
  "railway_station_alerts",
  "border_crossing_alerts",
  "ferry_alerts",
  "ford_alerts",
  "route_segment_recalculated",
  "trip_complete",
  "computation_step_completed",
  "trip_ready",
  "stage_updated",
  "validation_error",
  "computation_error",
  "computations_superseded",
] as const satisfies readonly MercureEvent["type"][];

export type MercureEventType = (typeof MERCURE_EVENT_TYPES)[number];

/**
 * Compile-time completeness check: if a `MercureEvent` variant exists that is
 * NOT listed in {@link MERCURE_EVENT_TYPES}, `_Complete` resolves to an object
 * type and assigning `true` below fails to type-check — so the build breaks the
 * moment the contract and the canonical list drift apart. (`satisfies` above
 * already rejects a typo / an entry that is not a real event type.)
 */
type _Complete =
  Exclude<MercureEvent["type"], MercureEventType> extends never
    ? true
    : {
        ERROR_event_types_missing_from_MERCURE_EVENT_TYPES: Exclude<
          MercureEvent["type"],
          MercureEventType
        >;
      };

export const MERCURE_EVENT_TYPES_ARE_EXHAUSTIVE: _Complete = true;

/**
 * The same list, as the generated schema sees it.
 *
 * `/trips/{id}/detail` enumerates the groups, so `openapi-typescript` turns them into a
 * literal union. Reading it back here means a group added, renamed or removed on the server
 * shows up as a compile error on both clients rather than as alerts that silently land in
 * the wrong bucket.
 */
type SchemaAlertGroup = NonNullable<
  NonNullable<
    NonNullable<
      components["schemas"]["TripDetail.jsonld"]["stages"]
    >[number]["alerts"]
  >[number]["group"]
>;

/**
 * Compile-time equality between {@link AlertGroup} and the schema's union: assigning `true`
 * fails to type-check unless each is assignable to the other, so neither a group missing
 * here nor one missing there can pass.
 */
type _AlertGroupsMatch = [AlertGroup] extends [SchemaAlertGroup]
  ? [SchemaAlertGroup] extends [AlertGroup]
    ? true
    : {
        ERROR_schema_has_groups_missing_from_AlertGroup: Exclude<
          SchemaAlertGroup,
          AlertGroup
        >;
      }
  : {
      ERROR_AlertGroup_has_groups_missing_from_the_schema: Exclude<
        AlertGroup,
        SchemaAlertGroup
      >;
    };

export const ALERT_GROUPS_MATCH_THE_SCHEMA: _AlertGroupsMatch = true;

/* -------------------------------------------------------------------------- *
 * ADR-065: Mercure is an invalidation channel, never a source of truth.
 * -------------------------------------------------------------------------- */

/**
 * What each event is for.
 *
 * - `data`   — carries trip content. Every field of it must also be reachable through a
 *              GET, which the coverage check below enforces.
 * - `signal` — says that something happened, and carries only what is needed to say it:
 *              progress counters, an error, a supersession notice, a "go and re-read".
 *
 * This half is declarative and reviewed in a pull request, not proven: no type can decide
 * whether a payload is content or a notification. What the compiler does enforce is that
 * the list is exhaustive, so a new event cannot slip in unclassified. `reconciliation.ts`
 * is the cross-check: everything classified `signal` there leaves the trip data untouched
 * (`computation_step_completed` returns the same state object outright).
 */
export const MERCURE_EVENT_KIND = {
  route_parsed: "data",
  stages_computed: "data",
  weather_fetched: "data",
  pois_scanned: "data",
  accommodations_found: "data",
  events_found: "data",
  supply_timeline: "data",
  terrain_alerts: "data",
  calendar_alerts: "data",
  wind_alerts: "data",
  bike_shop_alerts: "data",
  water_point_alerts: "data",
  health_service_alerts: "data",
  cultural_poi_alerts: "data",
  railway_station_alerts: "data",
  border_crossing_alerts: "data",
  ferry_alerts: "data",
  ford_alerts: "data",
  route_segment_recalculated: "data",
  trip_ready: "data",
  stage_updated: "data",
  trip_complete: "signal",
  computation_step_completed: "signal",
  computation_error: "signal",
  computations_superseded: "signal",
  validation_error: "signal",
} as const satisfies Record<MercureEventType, "data" | "signal">;

type SchemaOf<K extends keyof components["schemas"]> = components["schemas"][K];
type SchemaStage = NonNullable<SchemaOf<"TripDetail.jsonld">["stages"]>[number];
type SchemaAlert = NonNullable<NonNullable<SchemaStage["alerts"]>>[number];

/**
 * Fields of `P` that no property of `T` accounts for.
 *
 * Compared by key name, not by type: every property of the generated schemas is optional,
 * so a structural `extends` would succeed on anything and prove nothing.
 */
type Uncovered<P, T> = Exclude<keyof P, keyof T>;

type Covered<P, T, Label extends string> =
  Uncovered<P, T> extends never
    ? true
    : Record<Label, Uncovered<P, T>>;

/**
 * Every event that ships a list of alerts, derived rather than listed: adding one brings
 * it under the check with no list to remember to update.
 */
type AlertEventType = {
  [K in MercureEventType]: Extract<MercureEvent, { type: K }>["data"] extends {
    alerts: unknown[];
  }
    ? K
    : never;
}[MercureEventType];

type AlertElement<K extends AlertEventType> =
  Extract<MercureEvent, { type: K }>["data"] extends { alerts: (infer A)[] }
    ? A
    : never;

/**
 * An alert on the wire is an alert plus the stage it hangs off, so the target is the
 * schema's alert widened with the two addressing fields of the stage that carries it.
 */
type AlertTarget = SchemaAlert & Pick<SchemaStage, "stageId" | "dayNumber">;

type _AlertsAreCovered = {
  [K in AlertEventType]: Uncovered<AlertElement<K>, AlertTarget>;
}[AlertEventType] extends never
  ? true
  : {
      ERROR_alert_fields_no_GET_returns: {
        [K in AlertEventType]: Uncovered<AlertElement<K>, AlertTarget>;
      }[AlertEventType];
    };

/**
 * The reusable payload shapes, against the resources that serve them.
 *
 * These are the leaves of every `data` event; the envelopes around them (`alertsByStage`,
 * `affectedStageIds`, a bare `stageId`) are addressing, not content, and are deliberately
 * out of scope — they exist to say *where* an update lands, and no GET returns them.
 *
 * What this proves: no field is published over SSE that no GET can return. What it does
 * not prove: that the values agree, or that the equivalent read is convenient.
 * `route_segment_recalculated` carries a geometry delta whose equivalent is the whole of
 * `/trips/{id}/route` — covered structurally, but a client still re-reads more than it
 * was sent. That gap is named here rather than hidden.
 */
type _PayloadsAreCovered = [
  Covered<CoordinatePayload, SchemaOf<"Coordinate.jsonld">, "ERROR_CoordinatePayload">,
  Covered<PoiPayload, SchemaOf<"PointOfInterest.jsonld">, "ERROR_PoiPayload">,
  Covered<ResupplyPayload, SchemaOf<"Resupply.jsonld">, "ERROR_ResupplyPayload">,
  Covered<AccommodationPayload, SchemaOf<"Accommodation.jsonld">, "ERROR_AccommodationPayload">,
  Covered<EventPayload, SchemaOf<"Event.jsonld">, "ERROR_EventPayload">,
  Covered<HourlyWeatherSlotPayload, SchemaOf<"HourlyWeatherSlot.jsonld">, "ERROR_HourlyWeatherSlotPayload">,
  Covered<StagePayload, SchemaStage, "ERROR_StagePayload">,
  Covered<AlertPayload, SchemaAlert, "ERROR_AlertPayload">,
];

export const MERCURE_DATA_IS_READABLE_BY_GET: _AlertsAreCovered = true;
export const MERCURE_PAYLOADS_MATCH_THE_SCHEMA: _PayloadsAreCovered = [
  true,
  true,
  true,
  true,
  true,
  true,
  true,
  true,
];
