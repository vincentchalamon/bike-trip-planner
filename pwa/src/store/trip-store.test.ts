import { describe, expect, it } from "vitest";
import { getUndoableSlice, useTripStore } from "./trip-store";
import type { StageData } from "@/lib/validation/schemas";

function makeStage(dayNumber: number, distance = 50): StageData {
  return {
    dayNumber,
    distance,
    elevation: 0,
    elevationLoss: 0,
    startPoint: { lat: 0, lon: 0, ele: 0 },
    endPoint: { lat: 0, lon: 0, ele: 0 },
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
  };
}

describe("getUndoableSlice", () => {
  it("extracts the undoable fields from state", () => {
    const state = {
      stages: [
        {
          dayNumber: 1,
          distance: 80,
          elevation: 500,
          elevationLoss: 300,
          startPoint: { lat: 48.8, lon: 2.3, ele: 35 },
          endPoint: { lat: 48.9, lon: 2.4, ele: 42 },
          geometry: [],
          label: null,
          startLabel: "Paris",
          endLabel: "Meaux",
          weather: null,
          alerts: [],
          pois: [],
          accommodations: [],
          accommodationSearchRadiusKm: 5,
          isRestDay: false,
          supplyTimeline: [],
          events: [],
        },
      ],
      startDate: "2026-07-01",
      endDate: "2026-07-05",
      fatigueFactor: 0.9,
      elevationPenalty: 50,
      maxDistancePerDay: 100,
      averageSpeed: 15,
    };

    const slice = getUndoableSlice(state);

    expect(slice).toEqual({
      stages: state.stages,
      startDate: "2026-07-01",
      endDate: "2026-07-05",
      fatigueFactor: 0.9,
      elevationPenalty: 50,
      maxDistancePerDay: 100,
      averageSpeed: 15,
    });
  });

  it("returns a deep clone (no shared references)", () => {
    const stage = {
      dayNumber: 1,
      distance: 50,
      elevation: 200,
      elevationLoss: 100,
      startPoint: { lat: 45.0, lon: 3.0, ele: 0 },
      endPoint: { lat: 45.1, lon: 3.1, ele: 0 },
      geometry: [],
      label: null,
      startLabel: null,
      endLabel: null,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      accommodationSearchRadiusKm: 5,
      isRestDay: false,
      supplyTimeline: [],
      events: [],
    };

    const state = {
      stages: [stage],
      startDate: null,
      endDate: null,
      fatigueFactor: 0.9,
      elevationPenalty: 50,
      maxDistancePerDay: 100,
      averageSpeed: 15,
    };

    const slice = getUndoableSlice(state);

    // Mutating the slice should not affect the original
    slice.stages[0]!.distance = 999;
    expect(stage.distance).toBe(50);
  });
});

describe("selectedStageIndex (master/detail)", () => {
  it("defaults to 0", () => {
    useTripStore.getState().clearTrip();
    expect(useTripStore.getState().selectedStageIndex).toBe(0);
  });

  it("clamps an out-of-range index to the last stage", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2), makeStage(3)]);
    store.setSelectedStageIndex(99);
    expect(useTripStore.getState().selectedStageIndex).toBe(2);
  });

  it("rejects negative indices and falls back to 0", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2)]);
    store.setSelectedStageIndex(-3);
    expect(useTripStore.getState().selectedStageIndex).toBe(0);
  });

  it("clamps when stages shrink (deletion)", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2), makeStage(3)]);
    store.setSelectedStageIndex(2);
    store.deleteStage(2);
    expect(useTripStore.getState().selectedStageIndex).toBe(1);
  });

  it("clamps when setStages replaces with a shorter array", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2), makeStage(3), makeStage(4)]);
    store.setSelectedStageIndex(3);
    store.setStages([makeStage(1)]);
    expect(useTripStore.getState().selectedStageIndex).toBe(0);
  });
});
