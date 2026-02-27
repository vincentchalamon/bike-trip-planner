"use client";

import { create } from "zustand";
import { persist } from "zustand/middleware";
import { immer } from "zustand/middleware/immer";
import { TripStateSchema } from "@/lib/validation/schemas";
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
  sourceType: string | null;
  startDate: string | null;
  endDate: string | null;
  stages: StageData[];
  computationStatus: Record<string, string>;
  hasHydrated: boolean;

  setTrip: (trip: TripIdentity) => void;
  updateRouteData: (data: {
    totalDistance: number;
    totalElevation: number;
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
  updateStageAlerts: (stageIndex: number, alerts: AlertData[]) => void;
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
  updateTitle: (title: string) => void;
  updateDates: (startDate: string | null, endDate: string | null) => void;
  setComputationStatus: (status: Record<string, string>) => void;
  clearTrip: () => void;
  setHasHydrated: (value: boolean) => void;
}

const initialState = {
  trip: null,
  totalDistance: null,
  totalElevation: null,
  sourceType: null,
  startDate: null,
  endDate: null,
  stages: [],
  computationStatus: {},
  hasHydrated: false,
};

export const useTripStore = create<TripState>()(
  persist(
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

      updateStageAlerts: (stageIndex, alerts) =>
        set((state) => {
          if (state.stages[stageIndex]) {
            const existing = state.stages[stageIndex].alerts;
            state.stages[stageIndex].alerts = [...existing, ...alerts];
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

      updateTitle: (title) =>
        set((state) => {
          if (state.trip) state.trip.title = title;
        }),

      updateDates: (startDate, endDate) =>
        set((state) => {
          state.startDate = startDate;
          state.endDate = endDate;
        }),

      setComputationStatus: (status) =>
        set((state) => {
          state.computationStatus = status;
        }),

      clearTrip: () =>
        set((state) => {
          Object.assign(state, { ...initialState, hasHydrated: true });
        }),

      setHasHydrated: (value) =>
        set((state) => {
          state.hasHydrated = value;
        }),
    })),
    {
      name: "bike-trip-planner-storage",
      version: 1,
      partialize: (state) => {
        const {
          hasHydrated: _,
          setTrip: _a,
          updateRouteData: _b,
          setStages: _c,
          updateStageWeather: _d,
          updateStagePois: _e,
          updateStageAccommodations: _f,
          updateStageAlerts: _g,
          updateStageLabel: _h,
          addLocalAccommodation: _i,
          removeLocalAccommodation: _j,
          updateLocalAccommodation: _k,
          updateTitle: _l,
          updateDates: _m,
          setComputationStatus: _n,
          clearTrip: _o,
          setHasHydrated: _p,
          ...rest
        } = state;
        return rest;
      },
      onRehydrateStorage: () => (state) => {
        state?.setHasHydrated(true);
      },
      migrate: (persisted, version) => {
        if (version === 0 || version === 1) {
          return persisted as object;
        }
        return persisted as object;
      },
      merge: (persisted, current) => {
        const result = TripStateSchema.safeParse(persisted);
        if (result.success) {
          return { ...current, ...result.data };
        }
        // Corrupted localStorage: wipe gracefully (ADR-003)
        return current;
      },
    },
  ),
);
