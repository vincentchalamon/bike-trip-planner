export interface CoordinatePayload {
  lat: number;
  lon: number;
  ele: number;
}

export interface StagePayload {
  dayNumber: number;
  distance: number;
  elevation: number;
  startPoint: CoordinatePayload;
  endPoint: CoordinatePayload;
  geometry: CoordinatePayload[];
  label: string | null;
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
  } | null;
}

export interface AlertPayload {
  type: "critical" | "warning" | "nudge";
  message: string;
  lat: number | null;
  lon: number | null;
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
}

export type MercureEvent =
  | {
      type: "route_parsed";
      data: {
        totalDistance: number;
        totalElevation: number;
        sourceType: string;
        title: string | null;
      };
    }
  | { type: "stages_computed"; data: { stages: StagePayload[] } }
  | { type: "weather_fetched"; data: { stages: WeatherPayload[] } }
  | { type: "pois_scanned"; data: { stageIndex: number; pois: PoiPayload[] } }
  | {
      type: "accommodations_found";
      data: { stageIndex: number; accommodations: AccommodationPayload[] };
    }
  | {
      type: "terrain_alerts";
      data: { stageIndex: number; alerts: AlertPayload[] };
    }
  | {
      type: "calendar_alerts";
      data: { stageIndex: number; alerts: AlertPayload[] };
    }
  | {
      type: "wind_alerts";
      data: { stageIndex: number; alerts: AlertPayload[] };
    }
  | {
      type: "resupply_nudges";
      data: { stageIndex: number; alerts: AlertPayload[] };
    }
  | {
      type: "bike_shop_alerts";
      data: { stageIndex: number; alerts: AlertPayload[] };
    }
  | { type: "validation_error"; data: { code: string; message: string } }
  | {
      type: "computation_error";
      data: { computation: string; message: string; retryable: boolean };
    }
  | {
      type: "trip_complete";
      data: { computationStatus: Record<string, string> };
    };
