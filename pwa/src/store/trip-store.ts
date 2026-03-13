"use client";

import { create } from "zustand";
import { immer } from "zustand/middleware/immer";
import type {
  StageData,
  WeatherData,
  PoiData,
  AccommodationData,
  AlertData,
} from "@/lib/validation/schemas";

interface TripIdentity {
  id: string;
  title: string;
  sourceUrl: string;
}

interface TripState {
  trip: TripIdentity | null;
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  sourceType: string | null;
  startDate: string | null;
  endDate: string | null;
  fatigueFactor: number;
  elevationPenalty: number;
  ebikeMode: boolean;
  departureHour: number;
  stages: StageData[];
  computationStatus: Record<string, string>;

  setTrip: (trip: TripIdentity) => void;
  updateRouteData: (data: {
    totalDistance: number;
    totalElevation: number;
    totalElevationLoss: number;
    sourceType: string;
    title: string | null;
  }) => void;
  setStages: (stages: StageData[]) => void;
  updateStageWeather: (dayNumber: number, weather: WeatherData) => void;
  updateStagePois: (stageIndex: number, pois: PoiData[]) => void;
  updateStageAccommodations: (
    stageIndex: number,
    accs: AccommodationData[],
  ) => void;
  updateStageAlerts: (
    stageIndex: number,
    alerts: AlertData[],
    source: string,
  ) => void;
  updateStageLabel: (
    stageIndex: number,
    field: "startLabel" | "endLabel",
    value: string,
  ) => void;
  addLocalAccommodation: (stageIndex: number, acc: AccommodationData) => void;
  removeLocalAccommodation: (stageIndex: number, accIndex: number) => void;
  updateLocalAccommodation: (
    stageIndex: number,
    accIndex: number,
    data: Partial<AccommodationData>,
  ) => void;
  selectAccommodation: (
    stageIndex: number,
    accIndex: number,
    nextStageIndex: number | null,
  ) => void;
  deselectAccommodation: (stageIndex: number) => void;
  updateTitle: (title: string) => void;
  updateDates: (startDate: string | null, endDate: string | null) => void;
  updatePacingSettings: (
    fatigueFactor: number,
    elevationPenalty: number,
  ) => void;
  setEbikeMode: (ebikeMode: boolean) => void;
  setComputationStatus: (status: Record<string, string>) => void;
  deleteStage: (stageIndex: number) => void;
  clearTrip: () => void;
}

const initialState = {
  trip: null,
  totalDistance: null,
  totalElevation: null,
  totalElevationLoss: null,
  sourceType: null,
  startDate: null,
  endDate: null,
  fatigueFactor: 0.9,
  elevationPenalty: 50,
  ebikeMode: false,
  departureHour: 8,
  stages: [],
  computationStatus: {},
};

/**
 * Central Zustand store for all trip-related state (local-first, in-memory).
 *
 * Holds the trip identity, route metadata, stages, and per-stage derived data
 * (weather, POIs, alerts, accommodations, labels). All mutations use Immer for
 * immutable updates with mutable syntax.
 *
 * **Data flow:** The store is populated by two sources:
 * 1. User actions (form submissions, inline edits, accommodation CRUD)
 * 2. Mercure SSE events dispatched by {@link useMercure}'s `dispatchEvent()`
 *
 * **Alert merging:** `updateStageAlerts()` replaces alerts by `source` tag
 * (e.g. "terrain", "calendar", "wind"), allowing incremental updates without
 * losing alerts from other analyzers.
 *
 * **Side effects:** The store itself is side-effect-free. Reverse geocoding
 * for stage labels is triggered by `dispatchEvent()` in `use-mercure.ts`,
 * which calls `updateStageLabel()` asynchronously after resolution.
 *
 * **Lifecycle:** `clearTrip()` resets all state to initial values. There is
 * no persistence layer — data lives only in memory for the current session.
 */
export const useTripStore = create<TripState>()(
  immer((set) => ({
    ...initialState,

    setTrip: (trip) =>
      set((state) => {
        state.trip = trip;
      }),

    updateRouteData: (data) =>
      set((state) => {
        state.totalDistance = data.totalDistance;
        state.totalElevation = data.totalElevation;
        state.totalElevationLoss = data.totalElevationLoss;
        state.sourceType = data.sourceType;
        if (data.title && state.trip) {
          state.trip.title = data.title;
        }
      }),

    setStages: (stages) =>
      set((state) => {
        state.stages = stages;
      }),

    updateStageWeather: (dayNumber, weather) =>
      set((state) => {
        const stage = state.stages.find((s) => s.dayNumber === dayNumber);
        if (stage) stage.weather = weather;
      }),

    updateStagePois: (stageIndex, pois) =>
      set((state) => {
        if (state.stages[stageIndex]) {
          state.stages[stageIndex].pois = pois;
        }
      }),

    updateStageAccommodations: (stageIndex, accs) =>
      set((state) => {
        if (state.stages[stageIndex]) {
          state.stages[stageIndex].accommodations = accs;
        }
      }),

    updateStageAlerts: (stageIndex, alerts, source) =>
      set((state) => {
        if (state.stages[stageIndex]) {
          const taggedAlerts = alerts.map((a) => ({ ...a, source }));
          const kept = state.stages[stageIndex].alerts.filter(
            (a) => a.source !== source,
          );
          state.stages[stageIndex].alerts = [...kept, ...taggedAlerts];
        }
      }),

    updateStageLabel: (stageIndex, field, value) =>
      set((state) => {
        if (state.stages[stageIndex]) {
          state.stages[stageIndex][field] = value;
        }
      }),

    addLocalAccommodation: (stageIndex, acc) =>
      set((state) => {
        if (state.stages[stageIndex]) {
          state.stages[stageIndex].accommodations.push(acc);
        }
      }),

    removeLocalAccommodation: (stageIndex, accIndex) =>
      set((state) => {
        if (state.stages[stageIndex]) {
          state.stages[stageIndex].accommodations.splice(accIndex, 1);
        }
      }),

    updateLocalAccommodation: (stageIndex, accIndex, data) =>
      set((state) => {
        const acc = state.stages[stageIndex]?.accommodations[accIndex];
        if (acc) {
          Object.assign(acc, data);
        }
      }),

    selectAccommodation: (stageIndex, accIndex, nextStageIndex) =>
      set((state) => {
        const stage = state.stages[stageIndex];
        if (!stage) return;
        const acc = stage.accommodations[accIndex];
        if (!acc) return;
        // Keep only the selected accommodation
        stage.accommodations = [acc];
        stage.selectedAccommodation = acc;
        // Update stage endPoint to accommodation coordinates
        stage.endPoint = { lat: acc.lat, lon: acc.lon, ele: 0 };
        // Update next stage startPoint if applicable
        if (nextStageIndex !== null) {
          const nextStage = state.stages[nextStageIndex];
          if (nextStage) {
            nextStage.startPoint = { lat: acc.lat, lon: acc.lon, ele: 0 };
          }
        }
      }),

    deselectAccommodation: (stageIndex) =>
      set((state) => {
        const stage = state.stages[stageIndex];
        if (!stage) return;
        stage.selectedAccommodation = null;
      }),

    updateTitle: (title) =>
      set((state) => {
        if (state.trip) state.trip.title = title;
      }),

    updateDates: (startDate, endDate) =>
      set((state) => {
        state.startDate = startDate;
        state.endDate = endDate;
      }),

    updatePacingSettings: (fatigueFactor, elevationPenalty) =>
      set((state) => {
        state.fatigueFactor = fatigueFactor;
        state.elevationPenalty = elevationPenalty;
      }),

    setEbikeMode: (ebikeMode) =>
      set((state) => {
        state.ebikeMode = ebikeMode;
      }),

    setComputationStatus: (status) =>
      set((state) => {
        state.computationStatus = status;
      }),

    deleteStage: (stageIndex) =>
      set((state) => {
        state.stages.splice(stageIndex, 1);
        state.stages.forEach((s, i) => {
          s.dayNumber = i + 1;
        });
      }),

    clearTrip: () =>
      set((state) => {
        Object.assign(state, initialState);
      }),
  })),
);
