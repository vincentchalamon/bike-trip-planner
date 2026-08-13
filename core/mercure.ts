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
  pois: PoiPayload[];
  accommodations: AccommodationPayload[];
  selectedAccommodation: AccommodationPayload | null;
  events: EventPayload[];
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
  } | null;
}

export interface AlertActionPayload {
  kind: "auto_fix" | "detour" | "navigate" | "dismiss";
  label: string;
  payload: Record<string, unknown>;
}

export interface AlertPayload {
  /** Stable rule-variant identifier (backend `App\Enum\AlertCode`); null on legacy persisted alerts. */
  code?: string | null;
  type: "critical" | "warning" | "nudge";
  message: string;
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
  source: "osm" | "datatourisme";
  url?: string | null;
  description?: string | null;
  imageUrl?: string | null;
  wikipediaUrl?: string | null;
  openingHours?: string | null;
  phone?: string | null;
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
      data: { stages: StagePayload[]; affectedIndices?: number[] };
    }
  | { type: "weather_fetched"; data: { stages: WeatherPayload[] } }
  | {
      type: "pois_scanned";
      data: {
        stageIndex: number;
        pois: PoiPayload[];
        alerts?: AlertPayload[];
      };
    }
  | {
      type: "accommodations_found";
      data: {
        stageIndex: number;
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
          stageIndex: number;
          dayNumber: number;
          code: string;
          type: string;
          message: string;
          date: string;
        }[];
      };
    }
  | {
      type: "wind_alerts";
      data: { alerts: AlertPayload[] };
    }
  | {
      type: "bike_shop_alerts";
      data: {
        alerts: {
          stageIndex: number;
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
          stageIndex: number;
          code: string;
          type: string;
          message: string;
          dayNumber: number;
        }[];
        waterPointsByStage: {
          stageIndex: number;
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
        stageIndex: number;
        markers: SupplyMarker[];
      };
    }
  | {
      type: "health_service_alerts";
      data: {
        alerts: {
          stageIndex: number;
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
          stageIndex: number;
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
          stageIndex: number;
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
          stageIndex: number;
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
          stageIndex: number;
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
          stageIndex: number;
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
        stageIndex: number;
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
        stageIndex: number;
        events: EventPayload[];
      };
    }
  | { type: "validation_error"; data: { code: string; message: string } }
  | {
      type: "computation_error";
      data: { computation: string; message: string; retryable: boolean };
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
      // `stageIndex` without rebuilding the whole trip.
      type: "stage_updated";
      data: { stageIndex: number; stage: EnrichedStagePayload };
    };
