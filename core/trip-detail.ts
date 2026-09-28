import { DEFAULT_ACCOMMODATION_RADIUS_KM } from "./accommodation-constants";
import type { components } from "./schema";
import { EMPTY_RESUPPLY } from "./schemas";
import type { CoordinateData, StageData } from "./schemas";

// The one /detail -> store mapping, shared by the web trip page, the web shared
// page and both mobile hydrates (ADR-055). It used to exist four times and the
// copies drifted: the shared views dropped the supply timeline and events that
// ADR-068 made the server persist, and mobile dropped the cycle-network share.

export type TripDetailPayload = components["schemas"]["TripDetail.jsonld"];
export type TripDetailStage = NonNullable<TripDetailPayload["stages"]>[number];

/** Pacing and preferences a trip carries, with the defaults a new trip gets. */
export interface TripSettings {
  startDate: string | null;
  endDate: string | null;
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
  ebikeMode: boolean;
  departureHour: number;
  enabledAccommodationTypes: string[];
}

export const DEFAULT_TRIP_SETTINGS: TripSettings = {
  startDate: null,
  endDate: null,
  fatigueFactor: 0.9,
  elevationPenalty: 50,
  maxDistancePerDay: 80,
  averageSpeed: 15,
  ebikeMode: false,
  departureHour: 8,
  enabledAccommodationTypes: [],
};

// The API serializes dates as date-times; every client reads them as calendar days.
function toDateOnly(value: string | null | undefined): string | null {
  return value ? (value.split("T")[0] ?? null) : null;
}

export function tripSettingsFromDetail(
  detail: Pick<TripDetailPayload, keyof TripSettings>,
): TripSettings {
  return {
    startDate: toDateOnly(detail.startDate),
    endDate: toDateOnly(detail.endDate),
    fatigueFactor: detail.fatigueFactor ?? DEFAULT_TRIP_SETTINGS.fatigueFactor,
    elevationPenalty:
      detail.elevationPenalty ?? DEFAULT_TRIP_SETTINGS.elevationPenalty,
    maxDistancePerDay:
      detail.maxDistancePerDay ?? DEFAULT_TRIP_SETTINGS.maxDistancePerDay,
    averageSpeed: detail.averageSpeed ?? DEFAULT_TRIP_SETTINGS.averageSpeed,
    ebikeMode: detail.ebikeMode ?? DEFAULT_TRIP_SETTINGS.ebikeMode,
    departureHour: detail.departureHour ?? DEFAULT_TRIP_SETTINGS.departureHour,
    enabledAccommodationTypes: detail.enabledAccommodationTypes ?? [],
  };
}

function coordinate(
  point: TripDetailStage["startPoint"] | undefined,
): CoordinateData {
  return { lat: point?.lat ?? 0, lon: point?.lon ?? 0, ele: point?.ele ?? 0 };
}

/**
 * Map a persisted /detail stage to the store's shape. The summary carries no
 * geometry (ADR-057): it is merged in later from GET /route or the per-stage
 * detail. Every alert already carries its producing group (ADR-068), so none is
 * guessed here.
 *
 * The remaining casts cover the nested objects whose generated schema is looser
 * than the Zod one (every property optional): the server fills them completely.
 */
export function stageDataFromDetail(s: TripDetailStage): StageData {
  return {
    id: s.stageId ?? "",
    dayNumber: s.dayNumber ?? 0,
    distance: s.distance ?? 0,
    elevation: s.elevation ?? 0,
    elevationLoss: s.elevationLoss ?? 0,
    startPoint: coordinate(s.startPoint),
    endPoint: coordinate(s.endPoint),
    geometry: [],
    label: s.label ?? null,
    startLabel: s.startLabel ?? null,
    endLabel: s.endLabel ?? null,
    weather: (s.weather as StageData["weather"] | undefined) ?? null,
    alerts: (s.alerts as StageData["alerts"] | undefined) ?? [],
    resupply:
      (s.resupply as StageData["resupply"] | undefined) ?? EMPTY_RESUPPLY,
    accommodations:
      (s.accommodations as StageData["accommodations"] | undefined) ?? [],
    selectedAccommodation:
      (s.selectedAccommodation as StageData["selectedAccommodation"]) ?? null,
    accommodationSearchRadiusKm: DEFAULT_ACCOMMODATION_RADIUS_KM,
    isRestDay: s.isRestDay ?? false,
    onCycleNetwork: s.onCycleNetwork ?? 0,
    supplyTimeline:
      (s.supplyTimeline as StageData["supplyTimeline"] | undefined) ?? [],
    events: (s.events as StageData["events"] | undefined) ?? [],
  };
}
