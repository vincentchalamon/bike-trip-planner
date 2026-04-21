"use client";

import { create } from "zustand";
import { immer } from "zustand/middleware/immer";
import { DEFAULT_ACCOMMODATION_RADIUS_KM } from "@/lib/accommodation-constants";
import type {
  StageData,
  WeatherData,
  PoiData,
  AccommodationData,
  AlertData,
  SupplyMarkerData,
  EventData,
} from "@/lib/validation/schemas";
import type { AccommodationType } from "@/lib/accommodation-types";
import { FILTERABLE_ACCOMMODATION_TYPES } from "@/lib/accommodation-types";
import { createTemporalStore } from "@/store/temporal-middleware";
import type { SavedTrip } from "@/store/offline-store";

interface TripIdentity {
  id: string;
  title: string;
  sourceUrl: string;
}

interface TripState {
  trip: TripIdentity | null;
  isLocked: boolean;
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  sourceType: string | null;
  startDate: string | null;
  endDate: string | null;
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
  ebikeMode: boolean;
  departureHour: number;
  enabledAccommodationTypes: AccommodationType[];
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
  updateStageSupplyTimeline: (
    stageIndex: number,
    markers: SupplyMarkerData[],
  ) => void;
  setStageEvents: (stageIndex: number, events: EventData[]) => void;
  updateStageAccommodations: (
    stageIndex: number,
    accs: AccommodationData[],
    searchRadiusKm?: number,
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
  /** Internal setter — updates dates WITHOUT pushing to the undo history. */
  updateDatesInternal: (
    startDate: string | null,
    endDate: string | null,
  ) => void;
  updatePacingSettingsInternal: (
    fatigueFactor: number,
    elevationPenalty: number,
    maxDistancePerDay: number,
    averageSpeed: number,
  ) => void;
  setDepartureHour: (departureHour: number) => void;
  setEbikeMode: (ebikeMode: boolean) => void;
  setEnabledAccommodationTypes: (types: AccommodationType[]) => void;
  setComputationStatus: (status: Record<string, string>) => void;
  setIsLocked: (isLocked: boolean) => void;
  deleteStage: (stageIndex: number) => void;
  insertRestDay: (afterIndex: number) => void;
  /** Optimistically inserts a stage placeholder at `afterIndex + 1`. Undoable. */
  insertStagePlaceholder: (afterIndex: number, placeholder: StageData) => void;
  updateStageAfterRouteRecalculation: (
    stageIndex: number,
    data: {
      distance: number;
      elevationGain: number;
      coordinates: { lat: number; lon: number; ele: number }[];
    },
  ) => void;
  /**
   * Mode 1 — Atomic replacement of the stage array when `trip_ready` arrives.
   *
   * Preserves fields that the backend never ships in the enriched payload
   * (reverse-geocoded labels, accommodation search radius, locally-managed
   * accommodation selections). Accommodations whose endpoints match are kept
   * as-is so selection state is not lost across re-analyses.
   */
  applyTripReady: (stages: StageData[]) => void;
  /**
   * Mode 2 — Per-stage replacement when `stage_updated` arrives.
   *
   * Same preservation semantics as {@link applyTripReady} but for a single
   * slice. No-op if the index is out of bounds (stale message).
   */
  applyStageUpdate: (stageIndex: number, stage: StageData) => void;
  clearTrip: () => void;
  /** Hydrate the trip store from a {@link SavedTrip} snapshot (offline consultation). */
  loadFromSavedTrip: (trip: SavedTrip) => void;
}

const initialState = {
  trip: null,
  isLocked: false,
  totalDistance: null,
  totalElevation: null,
  totalElevationLoss: null,
  sourceType: null,
  startDate: null,
  endDate: null,
  fatigueFactor: 0.8,
  elevationPenalty: 100,
  maxDistancePerDay: 80,
  averageSpeed: 15,
  ebikeMode: false,
  departureHour: 8,
  enabledAccommodationTypes: [
    ...FILTERABLE_ACCOMMODATION_TYPES,
  ] as AccommodationType[],
  stages: [],
  computationStatus: {},
};

/**
 * The undoable slice of the trip store — the fields that users can accidentally
 * mutate and want to recover via Ctrl+Z.  Derived/computed fields (weather,
 * alerts, POIs, supply timelines, computationStatus) are intentionally excluded
 * because they are populated asynchronously by the backend and do not represent
 * intentional user actions.
 */
export interface UndoableSlice {
  stages: StageData[];
  startDate: string | null;
  endDate: string | null;
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
}

export function getUndoableSlice(state: {
  stages: StageData[];
  startDate: string | null;
  endDate: string | null;
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
}): UndoableSlice {
  // Deep clone via JSON to ensure Immer draft proxies are not captured.
  return JSON.parse(
    JSON.stringify({
      stages: state.stages,
      startDate: state.startDate,
      endDate: state.endDate,
      fatigueFactor: state.fatigueFactor,
      elevationPenalty: state.elevationPenalty,
      maxDistancePerDay: state.maxDistancePerDay,
      averageSpeed: state.averageSpeed,
    }),
  ) as UndoableSlice;
}

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
 *
 * **Undo/Redo:** User-facing mutations (stage add/delete, distance change,
 * date update, pacing adjustment) push a snapshot of {@link UndoableSlice}
 * onto the temporal history before applying the mutation.  The companion
 * {@link useTripTemporalStore} exposes `undo()`, `redo()`, `canUndo`, and
 * `canRedo`.
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

    updateStageSupplyTimeline: (stageIndex, markers) =>
      set((state) => {
        if (state.stages[stageIndex]) {
          state.stages[stageIndex].supplyTimeline = markers;
        }
      }),

    setStageEvents: (stageIndex, events) =>
      set((state) => {
        if (state.stages[stageIndex]) {
          state.stages[stageIndex].events = events;
        }
      }),

    updateStageAccommodations: (stageIndex, accs, searchRadiusKm) =>
      set((state) => {
        const stage = state.stages[stageIndex];
        if (!stage) return;
        if (!stage.selectedAccommodation) {
          stage.accommodations = accs;
        }
        if (searchRadiusKm !== undefined) {
          stage.accommodationSearchRadiusKm = searchRadiusKm;
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

    updateDates: (startDate, endDate) => {
      // Push snapshot before mutation so the user can undo date changes.
      useTripTemporalStore
        .getState()
        ._push(getUndoableSlice(useTripStore.getState()));
      set((state) => {
        state.startDate = startDate;
        state.endDate = endDate;
      });
    },

    updateDatesInternal: (startDate, endDate) =>
      set((state) => {
        state.startDate = startDate;
        state.endDate = endDate;
      }),

    updatePacingSettingsInternal: (
      fatigueFactor,
      elevationPenalty,
      maxDistancePerDay,
      averageSpeed,
    ) =>
      set((state) => {
        state.fatigueFactor = fatigueFactor;
        state.elevationPenalty = elevationPenalty;
        state.maxDistancePerDay = maxDistancePerDay;
        state.averageSpeed = averageSpeed;
      }),

    setDepartureHour: (departureHour) =>
      set((state) => {
        state.departureHour = departureHour;
      }),

    setEbikeMode: (ebikeMode) =>
      set((state) => {
        state.ebikeMode = ebikeMode;
      }),

    setEnabledAccommodationTypes: (types) =>
      set((state) => {
        state.enabledAccommodationTypes = types;
      }),

    setComputationStatus: (status) =>
      set((state) => {
        state.computationStatus = status;
      }),

    setIsLocked: (isLocked) =>
      set((state) => {
        state.isLocked = isLocked;
      }),

    deleteStage: (stageIndex) => {
      // Push snapshot before deletion so the user can undo accidental removal.
      useTripTemporalStore
        .getState()
        ._push(getUndoableSlice(useTripStore.getState()));
      set((state) => {
        state.stages.splice(stageIndex, 1);
        state.stages.forEach((s, i) => {
          s.dayNumber = i + 1;
        });
      });
    },

    insertRestDay: (afterIndex) => {
      // Push snapshot before insertion so the user can undo rest-day addition.
      useTripTemporalStore
        .getState()
        ._push(getUndoableSlice(useTripStore.getState()));
      set((state) => {
        const afterStage = state.stages[afterIndex];
        if (!afterStage) return;

        const restDay: StageData = {
          dayNumber: afterIndex + 2,
          distance: 0,
          elevation: 0,
          elevationLoss: 0,
          startPoint: { ...afterStage.endPoint },
          endPoint: { ...afterStage.endPoint },
          geometry: [],
          label: null,
          startLabel: afterStage.endLabel ?? null,
          endLabel: afterStage.endLabel ?? null,
          weather: null,
          alerts: [],
          pois: [],
          accommodations: [],
          accommodationSearchRadiusKm: DEFAULT_ACCOMMODATION_RADIUS_KM,
          isRestDay: true,
          supplyTimeline: [],
          events: [],
        };

        state.stages.splice(afterIndex + 1, 0, restDay);
        state.stages.forEach((s, i) => {
          s.dayNumber = i + 1;
        });
      });
    },

    insertStagePlaceholder: (afterIndex, placeholder) => {
      // Push snapshot before insertion so the user can undo stage addition.
      useTripTemporalStore
        .getState()
        ._push(getUndoableSlice(useTripStore.getState()));
      set((state) => {
        state.stages.splice(afterIndex + 1, 0, placeholder);
        state.stages.forEach((s, i) => {
          s.dayNumber = i + 1;
        });
      });
    },

    updateStageAfterRouteRecalculation: (stageIndex, data) =>
      set((state) => {
        const stage = state.stages[stageIndex];
        if (!stage) return;
        stage.distance = data.distance / 1000; // metres → km
        stage.elevation = data.elevationGain;
        stage.geometry = data.coordinates;
      }),

    applyTripReady: (stages) =>
      set((state) => {
        const existing = state.stages;
        state.stages = stages.map((incoming, i) => {
          const prev = existing[i];
          const endMatch =
            prev &&
            prev.endPoint.lat === incoming.endPoint.lat &&
            prev.endPoint.lon === incoming.endPoint.lon;
          const startMatch =
            prev &&
            prev.startPoint.lat === incoming.startPoint.lat &&
            prev.startPoint.lon === incoming.startPoint.lon;

          return {
            ...incoming,
            // Preserve client-only reverse-geocoded labels when endpoints
            // have not moved so we don't drop them on re-analysis.
            startLabel: startMatch
              ? (prev.startLabel ?? incoming.startLabel)
              : incoming.startLabel,
            endLabel: endMatch
              ? (prev.endLabel ?? incoming.endLabel)
              : incoming.endLabel,
            // Accommodation search radius is a UI-only knob that never
            // ships with the enriched payload — keep the user's setting
            // when the stage endpoint is stable.
            accommodationSearchRadiusKm: endMatch
              ? (prev.accommodationSearchRadiusKm ??
                DEFAULT_ACCOMMODATION_RADIUS_KM)
              : DEFAULT_ACCOMMODATION_RADIUS_KM,
          };
        });
      }),

    applyStageUpdate: (stageIndex, stage) =>
      set((state) => {
        const prev = state.stages[stageIndex];
        if (!prev) return;

        const endMatch =
          prev.endPoint.lat === stage.endPoint.lat &&
          prev.endPoint.lon === stage.endPoint.lon;
        const startMatch =
          prev.startPoint.lat === stage.startPoint.lat &&
          prev.startPoint.lon === stage.startPoint.lon;

        state.stages[stageIndex] = {
          ...stage,
          startLabel: startMatch
            ? (prev.startLabel ?? stage.startLabel)
            : stage.startLabel,
          endLabel: endMatch
            ? (prev.endLabel ?? stage.endLabel)
            : stage.endLabel,
          accommodationSearchRadiusKm: endMatch
            ? (prev.accommodationSearchRadiusKm ??
              DEFAULT_ACCOMMODATION_RADIUS_KM)
            : DEFAULT_ACCOMMODATION_RADIUS_KM,
        };
      }),

    loadFromSavedTrip: (trip) => {
      useTripTemporalStore.getState().clear();
      set((state) => {
        state.trip = {
          id: trip.id,
          title: trip.title,
          sourceUrl: trip.sourceUrl,
        };
        state.startDate = trip.startDate;
        state.endDate = trip.endDate;
        state.fatigueFactor = trip.fatigueFactor;
        state.elevationPenalty = trip.elevationPenalty;
        state.maxDistancePerDay = trip.maxDistancePerDay;
        state.averageSpeed = trip.averageSpeed;
        state.ebikeMode = trip.ebikeMode;
        state.departureHour = trip.departureHour;
        state.enabledAccommodationTypes = trip.enabledAccommodationTypes;
        state.stages = trip.stages;
        state.isLocked = true;
        state.totalDistance = trip.totalDistance ?? 0;
        state.totalElevation = trip.totalElevation ?? 0;
        state.totalElevationLoss = trip.totalElevationLoss ?? 0;
        state.sourceType = trip.sourceType ?? "";
      });
    },

    clearTrip: () => {
      // Clear undo/redo history when starting a fresh trip — history from a
      // previous trip session is no longer meaningful.
      useTripTemporalStore.getState().clear();
      set((state) => {
        // Preserve user-configured pacing settings, accommodation filters,
        // and dates across trip reloads — only reset trip data.
        const preserved = {
          fatigueFactor: state.fatigueFactor,
          elevationPenalty: state.elevationPenalty,
          maxDistancePerDay: state.maxDistancePerDay,
          averageSpeed: state.averageSpeed,
          ebikeMode: state.ebikeMode,
          departureHour: state.departureHour,
          enabledAccommodationTypes: state.enabledAccommodationTypes,
          startDate: state.startDate,
          endDate: state.endDate,
        };
        Object.assign(state, initialState, preserved);
      });
    },
  })),
);

/**
 * Companion temporal store that provides undo/redo for the trip store.
 *
 * Tracks the {@link UndoableSlice} (stages, dates, pacing settings).
 * Mutations that should be undoable push a snapshot via `_push()` before
 * applying their change.  `undo()` restores the previous snapshot by calling
 * `setStages` / updating individual fields on the trip store.
 */
export const useTripTemporalStore = createTemporalStore(
  // Read the current undoable slice from the trip store.
  () => getUndoableSlice(useTripStore.getState()),
  // Restore a snapshot into the trip store via internal store actions
  // (which go through the Immer-wrapped `set`) to maintain state integrity.
  (snapshot) => {
    const s = snapshot as UndoableSlice;
    const store = useTripStore.getState();
    store.setStages(s.stages);
    // Use individual actions so that Immer processes each mutation correctly.
    // Use the Internal variants to avoid re-pushing to the undo history.
    store.updateDatesInternal(s.startDate, s.endDate);
    store.updatePacingSettingsInternal(
      s.fatigueFactor,
      s.elevationPenalty,
      s.maxDistancePerDay,
      s.averageSpeed,
    );
  },
);
