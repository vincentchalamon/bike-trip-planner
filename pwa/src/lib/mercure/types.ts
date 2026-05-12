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
 * Per-stage AI analysis produced by the LLaMA pass 1 (issue #301 backend).
 *
 * Carried in each {@link EnrichedStagePayload} on the `trip_ready` Mercure
 * event. Mirrors the backend {@link StageAiAnalysis} DTO. Null/absent when
 * the AI pipeline is disabled, has failed, or has not yet completed.
 *
 * TODO(#450): wire via typegen once backend Stage DTO exposes aiAnalysis in OpenAPI.
 */
export interface StageAiAnalysisPayload {
  /** Short narrative paragraph (~80 words) summarising the stage. */
  narrative: string;
  /** Non-obvious facts the rider should know. */
  insights: string[];
  /** Actionable recommendations for the rider. */
  suggestions: string[];
  /** LLM model identifier (e.g. `"llama3.1:8b"`). */
  model: string;
  /** System prompt revision — bumps trigger client-side staleness checks. */
  promptVersion: number;
  /** RFC3339 timestamp when the analysis was generated. */
  generatedAt: string;
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
  /** LLaMA pass-1 stage analysis (issue #301). Null when the pipeline is off or pending. */
  aiAnalysis?: StageAiAnalysisPayload | null;
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

/**
 * Trip-level AI overview produced by the LLaMA pass 2 (issue #302 backend).
 *
 * Carried by the terminal `trip_ready` Mercure event. Mirrors the backend
 * `TripAiOverview` DTO (`api/src/Llm/Dto/TripAiOverview.php`) and the
 * OpenAPI-generated `components["schemas"]["TripAiOverview"]`.
 *
 * Null/absent when the AI pipeline is disabled, has failed, or has not yet
 * completed — consumers must treat the entire overview as optional.
 */
export interface TripAiOverviewPayload {
  /** Narrative paragraph (≈120 words) summarising the trip as a whole. */
  narrative: string;
  /** Cross-stage patterns the rider should be aware of (fatigue, weather…). */
  patterns: string[];
  /** Trip-level actionable recommendations. */
  recommendations: string[];
  /** Trip-level alerts spanning multiple stages (warnings flagged from patterns). */
  crossStageAlerts: string[];
  /** LLM model identifier (e.g. `"llama3.1:8b"`). */
  model: string;
  /** System prompt revision — bumps trigger client-side staleness checks. */
  promptVersion: number;
  /** RFC3339 timestamp when the overview was generated. */
  generatedAt: string;
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
        nudges: {
          stageIndex: number;
          type: "holiday" | "sunday";
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
        }[];
      };
    }
  | {
      type: "railway_station_alerts";
      data: {
        alerts: {
          stageIndex: number;
          dayNumber: number;
          type: string;
          message: string;
          action?: "navigate";
          actionLat?: number;
          actionLon?: number;
        }[];
      };
    }
  | {
      type: "border_crossing_alerts";
      data: {
        alerts: {
          stageIndex: number;
          dayNumber: number;
          type: "nudge";
          message: string;
          action: "navigate";
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
        aiOverview?: TripAiOverviewPayload | null;
      };
    }
  | {
      // Mode 2 — Per-stage update emitted after an inline modification
      // (Act 3). The frontend mutates the single slice identified by
      // `stageIndex` without rebuilding the whole trip.
      type: "stage_updated";
      data: { stageIndex: number; stage: EnrichedStagePayload };
    };
