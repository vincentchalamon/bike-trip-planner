import { describe, expect, it } from "vitest";
import type { AlertData, StageData } from "@btp/core";
import {
  dropStaleDateAlerts,
  pruneStaleRecomputing,
  reconcileResync,
  reconcileStageUpdate,
  reconcileTripReady,
  type StageAlert,
} from "@btp/core/reconciliation";

// Characterization tests for the pure reconciliation reducers extracted from
// trip-store.ts (#1013). They pin the race/edge behaviour of #840 (append
// trailing day, stale recomputing indices), #649 (client-only field
// preservation on a stable endpoint) and #787 (label preservation on resync)
// BEFORE the web store was rewired to compose these, and guard the mobile store
// that now composes them too.

const A = { lat: 1, lon: 1, ele: 0 };
const B = { lat: 2, lon: 2, ele: 0 };
const C = { lat: 3, lon: 3, ele: 0 };

function stage(overrides: Partial<StageData> = {}): StageData {
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 0,
    elevationLoss: 0,
    startPoint: A,
    endPoint: B,
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

function alert(overrides: Partial<StageAlert> = {}): AlertData {
  return {
    code: null,
    type: "nudge",
    message: "m",
    lat: null,
    lon: null,
    ...overrides,
  } as AlertData;
}

/** Assert a single-element result and return that element (satisfies noUncheckedIndexedAccess). */
function only(stages: StageData[]): StageData {
  expect(stages).toHaveLength(1);
  return stages[0] as StageData;
}

describe("reconcileResync (#787/#649)", () => {
  it("preserves prev labels on a stable endpoint when the incoming payload lacks them", () => {
    const prev = [stage({ startLabel: "Paris", endLabel: "Lyon" })];
    const incoming = [stage({ startLabel: null, endLabel: null })];
    const s = only(reconcileResync(prev, incoming));
    expect(s.startLabel).toBe("Paris");
    expect(s.endLabel).toBe("Lyon");
  });

  it("lets an incoming label win over the preserved one", () => {
    const prev = [stage({ startLabel: "Paris" })];
    const incoming = [stage({ startLabel: "Versailles" })];
    expect(only(reconcileResync(prev, incoming)).startLabel).toBe("Versailles");
  });

  it("drops the preserved label when the endpoint moved", () => {
    const prev = [stage({ endPoint: B, endLabel: "Lyon" })];
    const incoming = [stage({ endPoint: C, endLabel: null })];
    expect(only(reconcileResync(prev, incoming)).endLabel).toBeNull();
  });

  it("does not mutate the existing stages", () => {
    const prev = [stage({ startLabel: "Paris" })];
    reconcileResync(prev, [stage({ startLabel: null })]);
    expect(only(prev).startLabel).toBe("Paris");
  });
});

describe("reconcileTripReady (#649)", () => {
  it("preserves labels (prev wins), radius, supply timeline and non-empty lists on a stable endpoint", () => {
    const marker = {
      type: "water" as const,
      distanceFromStart: 1,
      lat: 1,
      lon: 1,
      water: [],
      food: [],
    };
    const acc = { name: "Gite" } as StageData["accommodations"][number];
    const prev = [
      stage({
        startLabel: "Paris",
        endLabel: "Lyon",
        accommodationSearchRadiusKm: 12,
        supplyTimeline: [marker],
        accommodations: [acc],
        selectedAccommodation: acc,
        alerts: [alert({ message: "keep" })],
        events: [{ name: "Fete" } as StageData["events"][number]],
      }),
    ];
    const incoming = [
      stage({
        startLabel: "IGNORED",
        endLabel: "IGNORED",
        accommodationSearchRadiusKm: 5,
        accommodations: [],
        alerts: [],
        events: [],
      }),
    ];
    const s = only(reconcileTripReady(prev, incoming));
    expect(s.startLabel).toBe("Paris");
    expect(s.endLabel).toBe("Lyon");
    expect(s.accommodationSearchRadiusKm).toBe(12);
    expect(s.supplyTimeline).toEqual([marker]);
    expect(s.accommodations).toEqual([acc]);
    expect(s.selectedAccommodation).toEqual(acc);
    expect(s.alerts).toHaveLength(1);
    expect(s.events).toHaveLength(1);
  });

  it("takes the incoming lists when prev is empty on a stable endpoint", () => {
    const acc = { name: "New" } as StageData["accommodations"][number];
    const prev = [stage({ accommodations: [], alerts: [], events: [] })];
    const incoming = [
      stage({ accommodations: [acc], alerts: [alert()], events: [] }),
    ];
    const s = only(reconcileTripReady(prev, incoming));
    expect(s.accommodations).toEqual([acc]);
    expect(s.alerts).toHaveLength(1);
  });

  it("resets radius to default and drops preserved fields when the endpoint moved", () => {
    const prev = [
      stage({ endPoint: B, accommodationSearchRadiusKm: 12, endLabel: "Lyon" }),
    ];
    const incoming = [stage({ endPoint: C, endLabel: null })];
    const s = only(reconcileTripReady(prev, incoming));
    expect(s.accommodationSearchRadiusKm).toBe(5);
    expect(s.endLabel).toBeNull();
  });
});

describe("reconcileStageUpdate (#840/#649)", () => {
  it("appends a stage_updated at stages.length and flags the trailing day (#840)", () => {
    const existing = [stage({ dayNumber: 1 })];
    const res = reconcileStageUpdate(existing, 1, stage({ dayNumber: 2 }));
    expect(res.appendedTrailingStage).toBe(true);
    expect(res.stages).toHaveLength(2);
  });

  it("ignores a stale event at an index beyond the trailing slot", () => {
    const existing = [stage()];
    const res = reconcileStageUpdate(existing, 5, stage());
    expect(res.appendedTrailingStage).toBe(false);
    expect(res.stages).toBe(existing);
  });

  it("keeps prev alerts wholesale on a stable endpoint when prev has alerts", () => {
    const existing = [stage({ alerts: [alert({ message: "prev" })] })];
    const res = reconcileStageUpdate(
      existing,
      0,
      stage({ alerts: [alert({ message: "incoming" })] }),
    );
    const s = only(res.stages);
    expect(s.alerts).toHaveLength(1);
    expect((s.alerts[0] as AlertData).message).toBe("prev");
  });

  it("preserves prev cultural_poi alerts and takes non-cultural from incoming when merging", () => {
    // endpoint moved -> merge branch (prev.alerts non-empty but endMatch false)
    const existing = [
      stage({
        endPoint: B,
        alerts: [alert({ source: "cultural_poi", message: "museum" })],
      }),
    ];
    const incoming = stage({
      endPoint: C,
      alerts: [
        alert({ source: "terrain", message: "gravel" }),
        alert({ source: "cultural_poi", message: "should-drop" }),
      ],
    });
    const s = only(reconcileStageUpdate(existing, 0, incoming).stages);
    const messages = s.alerts.map((a) => a.message);
    const sources = s.alerts.map((a) => a.source);
    expect(messages).toContain("museum"); // prev cultural preserved
    expect(messages).toContain("gravel"); // incoming non-cultural taken
    expect(messages).not.toContain("should-drop"); // incoming cultural dropped
    expect(sources.filter((x) => x === "cultural_poi")).toHaveLength(1);
  });

  it("keeps the supply timeline from prev", () => {
    const marker = {
      type: "food" as const,
      distanceFromStart: 2,
      lat: 1,
      lon: 1,
      water: [],
      food: [],
    };
    const existing = [stage({ supplyTimeline: [marker] })];
    const s = only(
      reconcileStageUpdate(existing, 0, stage({ supplyTimeline: [] })).stages,
    );
    expect(s.supplyTimeline).toEqual([marker]);
  });

  it("does not mutate the existing stages", () => {
    const existing = [stage({ endLabel: "Lyon" })];
    reconcileStageUpdate(existing, 0, stage({ endLabel: null }));
    expect(only(existing).endLabel).toBe("Lyon");
  });
});

describe("pruneStaleRecomputing (#840)", () => {
  it("drops indices that fell out of bounds and keeps the rest", () => {
    const pruned = pruneStaleRecomputing(2, new Set([0, 1, 2, 5]));
    expect([...pruned].sort()).toEqual([0, 1]);
  });

  it("returns a new set (does not mutate the input)", () => {
    const input = new Set([0, 3]);
    const pruned = pruneStaleRecomputing(1, input);
    expect(input.has(3)).toBe(true);
    expect(pruned).not.toBe(input);
  });
});

describe("dropStaleDateAlerts", () => {
  it("removes only calendar-group alerts, keeping the others", () => {
    const stages = [
      stage({
        alerts: [
          { ...alert({ message: "sunday" }), _group: "calendar" } as StageAlert,
          { ...alert({ message: "terrain" }), _group: "terrain" } as StageAlert,
        ],
      }),
    ];
    const s = only(dropStaleDateAlerts(stages));
    expect(s.alerts).toHaveLength(1);
    expect((s.alerts[0] as AlertData).message).toBe("terrain");
  });

  it("does not mutate the input stages", () => {
    const stages = [
      stage({
        alerts: [{ ...alert(), _group: "calendar" } as StageAlert],
      }),
    ];
    dropStaleDateAlerts(stages);
    expect(only(stages).alerts).toHaveLength(1);
  });
});
