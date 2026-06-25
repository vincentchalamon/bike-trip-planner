import { describe, expect, it } from "vitest";
import { getUndoableSlice, useTripStore } from "./trip-store";
import type {
  AccommodationData,
  AlertData,
  StageData,
} from "@/lib/validation/schemas";

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

function makeAccommodation(name: string): AccommodationData {
  return {
    name,
    type: "hotel",
    lat: 1,
    lon: 1,
    estimatedPriceMin: 50,
    estimatedPriceMax: 80,
    isExactPrice: false,
    possibleClosed: false,
    distanceToEndPoint: 0,
    source: "osm",
  };
}

function makeAlert(message: string): AlertData {
  return { type: "warning", message, source: "accommodations" };
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

describe("outOfZone", () => {
  it("defaults to false", () => {
    useTripStore.getState().clearTrip();
    expect(useTripStore.getState().outOfZone).toBe(false);
  });

  it("is set and reset by setOutOfZone", () => {
    const store = useTripStore.getState();
    store.setOutOfZone(true);
    expect(useTripStore.getState().outOfZone).toBe(true);
    store.setOutOfZone(false);
    expect(useTripStore.getState().outOfZone).toBe(false);
  });

  it("is reset to false by clearTrip", () => {
    const store = useTripStore.getState();
    store.setOutOfZone(true);
    store.clearTrip();
    expect(useTripStore.getState().outOfZone).toBe(false);
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

describe("applyTripReady preservation (recette #649)", () => {
  it("keeps accommodations/selection/alerts when the endpoint is stable", () => {
    const store = useTripStore.getState();
    const acc = makeAccommodation("Gîte du Tour");
    const current = makeStage(1);
    current.accommodations = [acc];
    current.selectedAccommodation = acc;
    current.alerts = [makeAlert("Stock up before the climb")];
    store.setStages([current]);

    // trip_ready payload arrives with empty accommodations/alerts.
    const incoming = makeStage(1);
    store.applyTripReady([incoming]);

    const result = useTripStore.getState().stages[0]!;
    expect(result.accommodations).toEqual([acc]);
    expect(result.selectedAccommodation).toEqual(acc);
    expect(result.alerts).toHaveLength(1);
  });

  it("takes incoming accommodations/alerts when the endpoint moved", () => {
    const store = useTripStore.getState();
    const old = makeAccommodation("Old");
    const current = makeStage(1);
    current.accommodations = [old];
    current.alerts = [makeAlert("old")];
    store.setStages([current]);

    const incoming = makeStage(1);
    incoming.endPoint = { lat: 9, lon: 9, ele: 0 };
    incoming.accommodations = [makeAccommodation("New")];
    incoming.alerts = [makeAlert("new")];
    store.applyTripReady([incoming]);

    const result = useTripStore.getState().stages[0]!;
    expect(result.accommodations[0]?.name).toBe("New");
    expect(result.alerts[0]?.message).toBe("new");
  });
});

describe("applyStageUpdate preservation (recette #649)", () => {
  it("keeps accommodations/selection when the endpoint is stable", () => {
    const store = useTripStore.getState();
    const acc = makeAccommodation("Gîte du Tour");
    const current = makeStage(1);
    current.accommodations = [acc];
    current.selectedAccommodation = acc;
    store.setStages([current]);

    // stage_updated payload after a re-route carries an empty list.
    const incoming = makeStage(1);
    store.applyStageUpdate(0, incoming);

    const result = useTripStore.getState().stages[0]!;
    expect(result.accommodations).toEqual([acc]);
    expect(result.selectedAccommodation).toEqual(acc);
  });

  it("takes incoming accommodations when the endpoint moved", () => {
    const store = useTripStore.getState();
    const current = makeStage(1);
    current.accommodations = [makeAccommodation("Old")];
    store.setStages([current]);

    const incoming = makeStage(1);
    incoming.endPoint = { lat: 9, lon: 9, ele: 0 };
    incoming.accommodations = [makeAccommodation("New")];
    store.applyStageUpdate(0, incoming);

    const result = useTripStore.getState().stages[0]!;
    expect(result.accommodations[0]?.name).toBe("New");
  });
});
