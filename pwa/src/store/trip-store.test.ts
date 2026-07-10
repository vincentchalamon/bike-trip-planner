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

describe("setStages label preservation (recette #649)", () => {
  it("keeps a client-resolved label when a resync payload has null labels and the endpoint is stable", () => {
    const store = useTripStore.getState();
    const current = makeStage(1);
    current.startLabel = "Lille";
    current.endLabel = "Roubaix";
    store.setStages([current]);

    // Resync /detail re-hydrate: server has not persisted labels yet (null).
    const incoming = makeStage(1);
    store.setStages([incoming]);

    const result = useTripStore.getState().stages[0]!;
    expect(result.startLabel).toBe("Lille");
    expect(result.endLabel).toBe("Roubaix");
  });

  it("prefers the incoming label when the server has persisted one", () => {
    const store = useTripStore.getState();
    const current = makeStage(1);
    current.startLabel = "Lille";
    store.setStages([current]);

    const incoming = makeStage(1);
    incoming.startLabel = "Lille Centre";
    store.setStages([incoming]);

    expect(useTripStore.getState().stages[0]!.startLabel).toBe("Lille Centre");
  });

  it("does not leak a label onto a stage whose coordinates differ", () => {
    const store = useTripStore.getState();
    const current = makeStage(1);
    current.startPoint = { lat: 50.63, lon: 3.05, ele: 0 };
    current.endPoint = { lat: 50.69, lon: 3.18, ele: 0 };
    current.startLabel = "Lille";
    current.endLabel = "Roubaix";
    store.setStages([current]);

    // A genuinely different stage lands at index 0 (null labels).
    const incoming = makeStage(1);
    incoming.startPoint = { lat: 48.85, lon: 2.35, ele: 0 };
    incoming.endPoint = { lat: 48.9, lon: 2.4, ele: 0 };
    store.setStages([incoming]);

    const result = useTripStore.getState().stages[0]!;
    expect(result.startLabel).toBeNull();
    expect(result.endLabel).toBeNull();
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

  it("keeps alerts when the endpoint is stable", () => {
    const store = useTripStore.getState();
    const current = makeStage(1);
    current.alerts = [makeAlert("Stock up before the climb")];
    store.setStages([current]);

    const incoming = makeStage(1);
    store.applyStageUpdate(0, incoming);

    const result = useTripStore.getState().stages[0]!;
    expect(result.alerts).toHaveLength(1);
    expect(result.alerts[0]?.message).toBe("Stock up before the climb");
  });

  it("takes incoming alerts when the endpoint moved", () => {
    const store = useTripStore.getState();
    const current = makeStage(1);
    current.alerts = [makeAlert("old")];
    store.setStages([current]);

    const incoming = makeStage(1);
    incoming.endPoint = { lat: 9, lon: 9, ele: 0 };
    incoming.alerts = [makeAlert("new")];
    store.applyStageUpdate(0, incoming);

    const result = useTripStore.getState().stages[0]!;
    expect(result.alerts[0]?.message).toBe("new");
  });

  it("preserves cultural-POI recommendations when the endpoint moved (recette #649)", () => {
    const store = useTripStore.getState();
    const cultural: AlertData = {
      ...makeAlert("Visit the abbey"),
      source: "cultural_poi",
    };
    const current = makeStage(1);
    current.alerts = [cultural, makeAlert("old terrain")];
    store.setStages([current]);

    // Accommodation selection re-routes the stage (endpoint moves) and carries a
    // terrain-only payload; the cultural recommendation must not vanish.
    const incoming = makeStage(1);
    incoming.endPoint = { lat: 9, lon: 9, ele: 0 };
    incoming.alerts = [makeAlert("new terrain")];
    store.applyStageUpdate(0, incoming);

    const result = useTripStore.getState().stages[0]!;
    expect(result.alerts.some((a) => a.source === "cultural_poi")).toBe(true);
    expect(result.alerts.some((a) => a.message === "new terrain")).toBe(true);
    expect(result.alerts.some((a) => a.message === "old terrain")).toBe(false);
  });
});

describe("recompute concurrency guard (#840)", () => {
  it("bumps recomputeVersion on every startStageRecomputation", () => {
    const store = useTripStore.getState();
    store.clearTrip();
    const before = useTripStore.getState().recomputeVersion;
    store.startStageRecomputation([0]);
    store.startStageRecomputation([1]);
    expect(useTripStore.getState().recomputeVersion).toBe(before + 2);
  });

  it("appends the new trailing day when its stage_updated lands out of bounds", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2), makeStage(3)]);

    // Reducing the last stage's distance split off a brand-new day at index 3;
    // its stage_updated lands at the next contiguous index.
    const newDay = makeStage(4, 25);
    newDay.endPoint = { lat: 1, lon: 1, ele: 0 };
    store.applyStageUpdate(3, newDay);

    const stages = useTripStore.getState().stages;
    expect(stages).toHaveLength(4);
    expect(stages[3]?.distance).toBe(25);
  });

  it("ignores a far out-of-range stage_updated (stale event)", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2)]);
    store.applyStageUpdate(5, makeStage(6));
    expect(useTripStore.getState().stages).toHaveLength(2);
  });

  it("prunes recomputing markers for indices that no longer exist (overlay unstuck)", () => {
    const store = useTripStore.getState();
    store.clearTrip();
    store.setStages([makeStage(1), makeStage(2), makeStage(3)]);
    store.startStageRecomputation([0, 1, 2]);
    // Day count shrinks: indices 1 and 2 can never receive a stage_updated.
    store.setStages([makeStage(1)]);
    expect([...useTripStore.getState().recomputingStages]).toEqual([0]);
  });

  it("lifts the overlay on a last-stage edit that adds a day, losing no data", () => {
    const store = useTripStore.getState();
    store.clearTrip();
    store.setStages([makeStage(1), makeStage(2), makeStage(3)]);
    // Edit last stage: mark the affected range (single last index here).
    store.startStageRecomputation([2]);

    // Backend re-splits and emits stage_updated for the edited stage and the
    // freshly created trailing day.
    store.applyStageUpdate(2, makeStage(3, 40));
    store.finishStageRecomputation(2);
    const newDay = makeStage(4, 20);
    newDay.endPoint = { lat: 2, lon: 2, ele: 0 };
    store.applyStageUpdate(3, newDay);

    const state = useTripStore.getState();
    expect(state.stages).toHaveLength(4);
    expect(state.stages[3]?.distance).toBe(20);
    // The set is empty, so use-mercure's `size === 0` branch lifts `processing`.
    expect(state.recomputingStages.size).toBe(0);
  });

  it("prunes out-of-bounds recomputing markers when a stage is deleted", () => {
    const store = useTripStore.getState();
    store.clearTrip();
    store.setStages([makeStage(1), makeStage(2), makeStage(3)]);
    store.startStageRecomputation([0, 1, 2]);
    // Deleting a stage shrinks the array; index 2 can no longer be settled.
    store.deleteStage(2);
    expect([...useTripStore.getState().recomputingStages].sort()).toEqual([
      0, 1,
    ]);
  });
});

describe("date window stays in sync with stage count (recette #649)", () => {
  it("extends endDate when a rest day is inserted", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2)]);
    store.updateDatesInternal("2026-07-10", "2026-07-11"); // 2 stages → 2 days
    store.insertRestDay(0);
    // 3 stages → 3 days: 10–12 July.
    expect(useTripStore.getState().endDate).toBe("2026-07-12");
  });

  it("extends endDate when a stage is inserted", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2)]);
    store.updateDatesInternal("2026-07-10", "2026-07-11");
    store.insertStagePlaceholder(0, makeStage(99));
    expect(useTripStore.getState().endDate).toBe("2026-07-12");
  });

  it("shrinks endDate when a stage is deleted", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2), makeStage(3)]);
    store.updateDatesInternal("2026-07-10", "2026-07-12"); // 3 stages → 3 days
    store.deleteStage(1);
    // 2 stages → 2 days: 10–11 July.
    expect(useTripStore.getState().endDate).toBe("2026-07-11");
  });

  it("leaves the (unset) dates untouched when no start date is set", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2)]);
    store.updateDatesInternal(null, null);
    store.insertRestDay(0);
    expect(useTripStore.getState().endDate).toBeNull();
  });

  it("extends endDate when a last-stage edit appends a trailing day (#840)", () => {
    const store = useTripStore.getState();
    store.setStages([makeStage(1), makeStage(2)]);
    store.updateDatesInternal("2026-07-10", "2026-07-11"); // 2 stages → 2 days
    // A last-stage distance edit splits off a new day whose stage_updated
    // lands at the next contiguous index.
    const newDay = makeStage(3, 20);
    newDay.endPoint = { lat: 3, lon: 3, ele: 0 };
    store.applyStageUpdate(2, newDay);
    // 3 stages → 3 days: 10–12 July.
    expect(useTripStore.getState().endDate).toBe("2026-07-12");
  });
});
