import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import type { AlertData, StageData } from "@btp/core";
import type {
  EnrichedStagePayload,
  MercureEvent,
  MercureEventType,
} from "@btp/core/mercure";
import { MERCURE_EVENT_TYPES } from "@btp/core/mercure";
import {
  reduceMercureEvent,
  type ReconciledState,
  type StageAlert,
} from "@btp/core/reconciliation";

// #1030 — full Mercure event reconciliation. Companion to reconciliation.test.ts
// (which pins the extracted characterization reducers): here we cover the pure
// `reduceMercureEvent` router that mirrors pwa/src/hooks/use-mercure.ts for
// EVERY event, plus a drift guard (in the spirit of AlertDocumentationTest) that
// fails loudly if the web hook and the shared core contract fall out of sync.

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
    resupply: {
      foodAtLunch: [],
      waterMorning: null,
      waterAfternoon: null,
      foodAtArrival: [],
    },
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

function baseState(overrides: Partial<ReconciledState> = {}): ReconciledState {
  return {
    totalDistance: null,
    totalElevation: null,
    totalElevationLoss: null,
    sourceType: null,
    title: null,
    stages: [],
    computationStatus: {},
    recomputingStages: new Set<number>(),
    ...overrides,
  };
}

function enriched(
  overrides: Partial<EnrichedStagePayload> = {},
): EnrichedStagePayload {
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 0,
    elevationLoss: 0,
    startPoint: A,
    endPoint: B,
    geometry: [],
    label: null,
    weather: null,
    alerts: [],
    resupply: {
      foodAtLunch: [],
      waterMorning: null,
      waterAfternoon: null,
      foodAtArrival: [],
    },
    accommodations: [],
    selectedAccommodation: null,
    events: [],
    ...overrides,
  };
}

const weather = {
  icon: "sun",
  description: "clear",
  tempMin: 10,
  tempMax: 20,
  windSpeed: 5,
  windDirection: "N",
  precipitationProbability: 0,
  humidity: 50,
  comfortIndex: 100,
  relativeWindDirection: "unknown" as const,
};

describe("reduceMercureEvent — trip-level events", () => {
  it("route_parsed writes route totals, source and title", () => {
    const next = reduceMercureEvent(baseState(), {
      type: "route_parsed",
      data: {
        totalDistance: 120,
        totalElevation: 800,
        totalElevationLoss: 700,
        sourceType: "komoot",
        title: "Vercors",
      },
    });
    expect(next.totalDistance).toBe(120);
    expect(next.totalElevation).toBe(800);
    expect(next.totalElevationLoss).toBe(700);
    expect(next.sourceType).toBe("komoot");
    expect(next.title).toBe("Vercors");
  });

  it("route_parsed keeps the previous title when the payload omits it", () => {
    const next = reduceMercureEvent(baseState({ title: "Kept" }), {
      type: "route_parsed",
      data: {
        totalDistance: 1,
        totalElevation: 1,
        totalElevationLoss: 1,
        sourceType: "gpx",
        title: null,
      },
    });
    expect(next.title).toBe("Kept");
  });

  it("trip_complete stores the status and clears recomputing markers", () => {
    const next = reduceMercureEvent(
      baseState({ recomputingStages: new Set([0, 1]) }),
      {
        type: "trip_complete",
        data: { computationStatus: { weather: "done" } },
      },
    );
    expect(next.computationStatus).toEqual({ weather: "done" });
    expect(next.recomputingStages.size).toBe(0);
  });

  it("validation_error clears recomputing markers", () => {
    const next = reduceMercureEvent(
      baseState({ recomputingStages: new Set([2]) }),
      { type: "validation_error", data: { code: "x", message: "bad" } },
    );
    expect(next.recomputingStages.size).toBe(0);
  });

  it("computation_error clears recomputing only when not retryable", () => {
    const retry = reduceMercureEvent(
      baseState({ recomputingStages: new Set([0]) }),
      {
        type: "computation_error",
        data: { computation: "weather", message: "e", retryable: true },
      },
    );
    expect(retry.recomputingStages.size).toBe(1);

    const fatal = reduceMercureEvent(
      baseState({ recomputingStages: new Set([0]) }),
      {
        type: "computation_error",
        data: { computation: "weather", message: "e", retryable: false },
      },
    );
    expect(fatal.recomputingStages.size).toBe(0);
  });

  it("computation_step_completed is a no-op (same state reference)", () => {
    const state = baseState();
    const next = reduceMercureEvent(state, {
      type: "computation_step_completed",
      data: { step: "s", category: "route", completed: 1, total: 3 },
    });
    expect(next).toBe(state);
  });
});

describe("reduceMercureEvent — per-stage enrichment", () => {
  it("weather_fetched sets weather on the stage matching dayNumber", () => {
    const state = baseState({
      stages: [stage({ dayNumber: 1 }), stage({ dayNumber: 2 })],
    });
    const next = reduceMercureEvent(state, {
      type: "weather_fetched",
      data: { stages: [{ dayNumber: 2, weather }] },
    });
    expect(next.stages[0]!.weather).toBeNull();
    expect(next.stages[1]!.weather).toEqual(weather);
  });

  it("pois_scanned sets resupply and tags optional alerts with the pois group", () => {
    const state = baseState({ stages: [stage()] });
    const next = reduceMercureEvent(state, {
      type: "pois_scanned",
      data: {
        stageIndex: 0,
        resupply: {
          foodAtLunch: [
            {
              name: "Fort",
              category: "castle",
              lat: 1,
              lon: 1,
              distanceFromStart: 3,
            },
          ],
          waterMorning: null,
          waterAfternoon: null,
          foodAtArrival: [],
        },
        alerts: [{ type: "nudge", message: "poi", lat: null, lon: null }],
      },
    });
    expect(next.stages[0]!.resupply.foodAtLunch).toHaveLength(1);
    expect((next.stages[0]!.alerts[0] as StageAlert)._group).toBe("pois");
  });

  it("supply_timeline replaces the stage markers", () => {
    const marker = {
      type: "water" as const,
      distanceFromStart: 1,
      lat: 1,
      lon: 1,
      water: [],
      food: [],
    };
    const next = reduceMercureEvent(baseState({ stages: [stage()] }), {
      type: "supply_timeline",
      data: { stageIndex: 0, markers: [marker] },
    });
    expect(next.stages[0]!.supplyTimeline).toEqual([marker]);
  });

  it("events_found replaces the stage events", () => {
    const next = reduceMercureEvent(baseState({ stages: [stage()] }), {
      type: "events_found",
      data: {
        stageIndex: 0,
        events: [
          {
            name: "Fete",
            type: "festival",
            lat: 1,
            lon: 1,
            startDate: "2026-08-01",
            endDate: "2026-08-02",
            url: null,
            description: null,
            priceMin: null,
            distanceToEndPoint: 0,
            source: "openagenda",
            wikidataId: null,
          },
        ],
      },
    });
    expect(next.stages[0]!.events).toHaveLength(1);
  });

  it("accommodations_found sets accommodations and radius, but not over a rider's selection", () => {
    const acc = { name: "Gite" } as StageData["accommodations"][number];
    const state = baseState({
      stages: [
        stage(),
        stage({ selectedAccommodation: acc, accommodations: [acc] }),
      ],
    });
    const next = reduceMercureEvent(state, {
      type: "accommodations_found",
      data: {
        stageIndex: 0,
        accommodations: [
          { name: "New" } as StageData["accommodations"][number],
        ],
        searchRadiusKm: 12,
      },
    });
    expect(next.stages[0]!.accommodations).toHaveLength(1);
    expect(next.stages[0]!.accommodationSearchRadiusKm).toBe(12);

    const kept = reduceMercureEvent(state, {
      type: "accommodations_found",
      data: {
        stageIndex: 1,
        accommodations: [
          { name: "Ignored" } as StageData["accommodations"][number],
        ],
      },
    });
    expect(kept.stages[1]!.accommodations).toEqual([acc]);
  });
});

describe("reduceMercureEvent — alert groups", () => {
  it("terrain_alerts fans alertsByStage onto the matching stages under the terrain group", () => {
    const state = baseState({ stages: [stage(), stage()] });
    const next = reduceMercureEvent(state, {
      type: "terrain_alerts",
      data: {
        alertsByStage: {
          "1": [{ type: "warning", message: "gravel", lat: null, lon: null }],
        },
      },
    });
    expect(next.stages[0]!.alerts).toHaveLength(0);
    expect(next.stages[1]!.alerts).toHaveLength(1);
    expect((next.stages[1]!.alerts[0] as StageAlert)._group).toBe("terrain");
  });

  it("alert groups coexist: a later group never blanks another analyzer's alerts", () => {
    let state = baseState({ stages: [stage()] });
    state = reduceMercureEvent(state, {
      type: "terrain_alerts",
      data: {
        alertsByStage: {
          "0": [{ type: "warning", message: "t", lat: null, lon: null }],
        },
      },
    });
    state = reduceMercureEvent(state, {
      type: "wind_alerts",
      data: { alerts: [{ type: "nudge", message: "w", lat: null, lon: null }] },
    });
    const sources = state.stages[0]!.alerts.map(
      (a) => (a as StageAlert)._group,
    ).sort();
    expect(sources).toEqual(["terrain", "wind"]);
  });

  it("calendar_alerts is a full replacement: a stage dropped from the new set loses its stale nudge", () => {
    const stale: StageAlert = {
      type: "nudge",
      message: "Sunday",
      lat: null,
      lon: null,
      _group: "calendar",
    };
    const state = baseState({
      stages: [stage(), stage({ alerts: [{ ...stale }] })],
    });
    const next = reduceMercureEvent(state, {
      type: "calendar_alerts",
      data: {
        alerts: [
          {
            stageIndex: 0,
            dayNumber: 1,
            code: "SUNDAY",
            type: "nudge",
            message: "Sunday now here",
            date: "2026-08-02",
          },
        ],
      },
    });
    expect(next.stages[0]!.alerts).toHaveLength(1);
    expect(next.stages[1]!.alerts).toHaveLength(0);
  });

  it("action-bearing alert groups carry their server-built action and source tag", () => {
    const action = {
      kind: "navigate" as const,
      label: "Voir",
      payload: { lat: 4, lon: 5 },
    };
    const next = reduceMercureEvent(baseState({ stages: [stage()] }), {
      type: "ferry_alerts",
      data: {
        alerts: [
          {
            stageIndex: 0,
            dayNumber: 1,
            code: "FERRY",
            type: "warning",
            message: "bac",
            action,
            lat: 4,
            lon: 5,
          },
        ],
      },
    });
    const alert = next.stages[0]!.alerts[0] as AlertData;
    expect(alert.source).toBe("ferry");
    expect(alert.action?.kind).toBe("navigate");
    expect(alert.action?.payload).toEqual({ lat: 4, lon: 5 });
  });

  // Field-mapping coverage for the remaining groups: each must land on the
  // right stage, carry its `_group` tag, and preserve the mapped message /
  // source / action — a swapped mapping would otherwise only be caught by the
  // no-fallthrough smoke test, which stays green on a wrong field.
  it("bike_shop_alerts tags the stage alert with the bike_shop group", () => {
    const next = reduceMercureEvent(baseState({ stages: [stage()] }), {
      type: "bike_shop_alerts",
      data: {
        alerts: [
          {
            stageIndex: 0,
            dayNumber: 1,
            code: "BS",
            type: "nudge",
            message: "Vélociste",
          },
        ],
      },
    });
    const a = next.stages[0]!.alerts[0] as StageAlert;
    expect(a._group).toBe("bike_shop");
    expect(a.message).toBe("Vélociste");
  });

  it("water_point_alerts maps the water_point source and group", () => {
    const next = reduceMercureEvent(baseState({ stages: [stage()] }), {
      type: "water_point_alerts",
      data: {
        alerts: [
          {
            stageIndex: 0,
            dayNumber: 1,
            code: "WP",
            type: "nudge",
            message: "Fontaine",
          },
        ],
        waterPointsByStage: [],
      },
    });
    const a = next.stages[0]!.alerts[0] as StageAlert;
    expect(a._group).toBe("water_point");
    expect(a.source).toBe("water_point");
  });

  it("health_service_alerts tags the stage alert with the health_service group", () => {
    const next = reduceMercureEvent(baseState({ stages: [stage()] }), {
      type: "health_service_alerts",
      data: {
        alerts: [
          {
            stageIndex: 0,
            dayNumber: 1,
            code: "HS",
            type: "nudge",
            message: "Pharmacie",
          },
        ],
      },
    });
    const a = next.stages[0]!.alerts[0] as StageAlert;
    expect(a._group).toBe("health_service");
    expect(a.message).toBe("Pharmacie");
  });

  it("cultural_poi_alerts maps the cultural_poi source and poi metadata", () => {
    const next = reduceMercureEvent(baseState({ stages: [stage()] }), {
      type: "cultural_poi_alerts",
      data: {
        alerts: [
          {
            stageIndex: 0,
            dayNumber: 1,
            code: "CP",
            type: "nudge",
            message: "Musée",
            lat: 1,
            lon: 1,
            poiName: "Louvre",
            poiType: "museum",
            poiLat: 1,
            poiLon: 1,
            distanceFromRoute: 120,
          },
        ],
      },
    });
    const a = next.stages[0]!.alerts[0] as StageAlert;
    expect(a._group).toBe("cultural_poi");
    expect(a.source).toBe("cultural_poi");
    expect(a.poiName).toBe("Louvre");
  });

  it("railway_station_alerts maps the source and optional navigate action", () => {
    const next = reduceMercureEvent(baseState({ stages: [stage()] }), {
      type: "railway_station_alerts",
      data: {
        alerts: [
          {
            stageIndex: 0,
            dayNumber: 1,
            code: "RS",
            type: "nudge",
            message: "Gare",
            action: {
              kind: "navigate",
              label: "Voir",
              payload: { lat: 1, lon: 2 },
            },
          },
        ],
      },
    });
    const a = next.stages[0]!.alerts[0] as StageAlert;
    expect(a._group).toBe("railway_station");
    expect(a.source).toBe("railway_station");
    expect(a.action?.payload).toEqual({ lat: 1, lon: 2 });
  });

  it("border_crossing_alerts maps the source and action", () => {
    const next = reduceMercureEvent(baseState({ stages: [stage()] }), {
      type: "border_crossing_alerts",
      data: {
        alerts: [
          {
            stageIndex: 0,
            dayNumber: 1,
            code: "BC",
            type: "nudge",
            message: "Frontière",
            action: {
              kind: "navigate",
              label: "Voir",
              payload: { lat: 3, lon: 4 },
            },
            lat: 3,
            lon: 4,
          },
        ],
      },
    });
    const a = next.stages[0]!.alerts[0] as StageAlert;
    expect(a._group).toBe("border_crossing");
    expect(a.source).toBe("border_crossing");
  });

  it("ford_alerts maps the ford source and action", () => {
    const next = reduceMercureEvent(baseState({ stages: [stage()] }), {
      type: "ford_alerts",
      data: {
        alerts: [
          {
            stageIndex: 0,
            dayNumber: 1,
            code: "FD",
            type: "warning",
            message: "Gué",
            action: {
              kind: "navigate",
              label: "Voir",
              payload: { lat: 5, lon: 6 },
            },
            lat: 5,
            lon: 6,
          },
        ],
      },
    });
    const a = next.stages[0]!.alerts[0] as StageAlert;
    expect(a._group).toBe("ford");
    expect(a.source).toBe("ford");
  });
});

describe("reduceMercureEvent — structural / terminal events", () => {
  it("route_segment_recalculated rewrites geometry (m→km), drops cultural_poi alerts and clears recomputing", () => {
    const cultural: StageAlert = {
      type: "nudge",
      message: "museum",
      lat: null,
      lon: null,
      _group: "cultural_poi",
    };
    const terrain: StageAlert = {
      type: "warning",
      message: "gravel",
      lat: null,
      lon: null,
      _group: "terrain",
    };
    const state = baseState({
      stages: [stage({ alerts: [{ ...cultural }, { ...terrain }] })],
      recomputingStages: new Set([0]),
    });
    const next = reduceMercureEvent(state, {
      type: "route_segment_recalculated",
      data: {
        stageIndex: 0,
        reason: "detour",
        distance: 42000,
        elevationGain: 300,
        duration: 3600,
        coordinates: [C],
      },
    });
    expect(next.stages[0]!.distance).toBe(42);
    expect(next.stages[0]!.elevation).toBe(300);
    expect(next.stages[0]!.geometry).toEqual([C]);
    const messages = next.stages[0]!.alerts.map((a) => a.message);
    expect(messages).toEqual(["gravel"]);
    expect(next.recomputingStages.size).toBe(0);
  });

  it("stages_computed (full replace) preserves labels on a stable endpoint and prunes stale recomputing", () => {
    const state = baseState({
      stages: [stage({ endLabel: "Lyon", startLabel: "Paris" })],
      recomputingStages: new Set([0, 3]),
    });
    const next = reduceMercureEvent(state, {
      type: "stages_computed",
      data: {
        stages: [
          {
            dayNumber: 1,
            distance: 60,
            elevation: 100,
            elevationLoss: 90,
            startPoint: A,
            endPoint: B,
            geometry: [],
            label: null,
          },
        ],
      },
    });
    expect(next.stages[0]!.startLabel).toBe("Paris");
    expect(next.stages[0]!.endLabel).toBe("Lyon");
    expect([...next.recomputingStages]).toEqual([0]);
  });

  it("stages_computed (partial update) keeps the derived label on an unaffected stage when the payload omits it", () => {
    // Mirrors use-mercure.ts:90 (`label: s.label ?? existing.label`): on the
    // unaffected branch a null payload label must NOT blank the reverse-geocoded
    // label already on the stage; only the affected stage is replaced.
    const state = baseState({
      stages: [
        stage({ dayNumber: 1, label: "Col du Galibier" }),
        stage({ dayNumber: 2, label: "Briançon" }),
      ],
      recomputingStages: new Set([1]),
    });
    const next = reduceMercureEvent(state, {
      type: "stages_computed",
      data: {
        affectedIndices: [1],
        stages: [
          {
            dayNumber: 1,
            distance: 61,
            elevation: 100,
            elevationLoss: 90,
            startPoint: A,
            endPoint: B,
            geometry: [],
            label: null,
          },
          {
            dayNumber: 2,
            distance: 40,
            elevation: 10,
            elevationLoss: 5,
            startPoint: B,
            endPoint: C,
            geometry: [],
            label: "Névache",
          },
        ],
      },
    });
    // Unaffected stage: core field updated, derived label preserved.
    expect(next.stages[0]!.distance).toBe(61);
    expect(next.stages[0]!.label).toBe("Col du Galibier");
    // Affected stage: fully replaced by the payload.
    expect(next.stages[1]!.label).toBe("Névache");
    // In-range recomputing marker is kept (pruning only drops out-of-range).
    expect([...next.recomputingStages]).toEqual([1]);
  });

  it("trip_ready delegates to reconcileTripReady, stores status and clears recomputing", () => {
    const acc = { name: "Gite" } as StageData["accommodations"][number];
    const state = baseState({
      stages: [stage({ endLabel: "Lyon", accommodations: [acc] })],
      recomputingStages: new Set([0]),
    });
    const next = reduceMercureEvent(state, {
      type: "trip_ready",
      data: {
        stages: [enriched({ label: null, accommodations: [] })],
        computationStatus: { all: "done" },
      },
    });
    // reconcileTripReady preserves the prev label + non-empty accommodations on a stable endpoint.
    expect(next.stages[0]!.endLabel).toBe("Lyon");
    expect(next.stages[0]!.accommodations).toEqual([acc]);
    expect(next.computationStatus).toEqual({ all: "done" });
    expect(next.recomputingStages.size).toBe(0);
  });

  it("stage_updated delegates to reconcileStageUpdate and removes the index from recomputing", () => {
    const state = baseState({
      stages: [stage({ dayNumber: 1, endLabel: "Lyon" })],
      recomputingStages: new Set([0, 1]),
    });
    const next = reduceMercureEvent(state, {
      type: "stage_updated",
      data: { stageIndex: 0, stage: enriched({ dayNumber: 1, label: null }) },
    });
    expect(next.stages[0]!.endLabel).toBe("Lyon"); // preserved on stable endpoint
    expect([...next.recomputingStages]).toEqual([1]);
  });
});

describe("reduceMercureEvent — purity", () => {
  it("does not mutate the input state's stages or recomputing set", () => {
    const stages = [stage()];
    const recomputingStages = new Set([0]);
    const state = baseState({ stages, recomputingStages });
    reduceMercureEvent(state, {
      type: "pois_scanned",
      data: {
        stageIndex: 0,
        resupply: {
          foodAtLunch: [
            { name: "x", category: "c", lat: 1, lon: 1, distanceFromStart: 0 },
          ],
          waterMorning: null,
          waterAfternoon: null,
          foodAtArrival: [],
        },
      },
    });
    reduceMercureEvent(state, {
      type: "trip_complete",
      data: { computationStatus: {} },
    });
    expect(state.stages[0]!.resupply.foodAtLunch).toHaveLength(0);
    expect(recomputingStages.has(0)).toBe(true);
  });
});

// ---------------------------------------------------------------------------
// Drift guard — keeps the @btp/core contract in sync web <-> mobile.
//
// In the spirit of api/tests/Unit/AlertDocumentationTest.php: no hand-kept map.
// It reads the web hook's switch and asserts, in BOTH directions, that the set
// of events it dispatches equals the canonical MERCURE_EVENT_TYPES that the
// core reducer covers. A new SSE event added to the contract or the web hook
// without wiring the shared reducer (or vice versa) fails here loudly. The
// core reducer's own exhaustiveness (its `never` default) and the compile-time
// MERCURE_EVENT_TYPES_ARE_EXHAUSTIVE assertion cover the type-level direction.
// ---------------------------------------------------------------------------
describe("Mercure contract drift guard (#1030)", () => {
  // vitest runs with cwd = the pwa workspace root.
  const useMercurePath = resolve(process.cwd(), "src/hooks/use-mercure.ts");
  const source = readFileSync(useMercurePath, "utf8");
  const handledInHook = new Set(
    [...source.matchAll(/^\s*case\s+"([a-z_]+)":/gm)].map((m) => m[1]!),
  );
  const canonical = new Set<string>(MERCURE_EVENT_TYPES);

  it("every event dispatched by use-mercure.ts is a canonical core event", () => {
    const orphanInHook = [...handledInHook].filter((t) => !canonical.has(t));
    expect(orphanInHook, "web hook handles events not in @btp/core").toEqual(
      [],
    );
  });

  it("every canonical core event is dispatched by use-mercure.ts", () => {
    const missingFromHook = [...canonical].filter((t) => !handledInHook.has(t));
    expect(
      missingFromHook,
      "@btp/core events not handled by the web hook",
    ).toEqual([]);
  });

  it("the core reducer handles every canonical event at runtime (no fallthrough)", () => {
    const stubData: Record<MercureEventType, unknown> = {
      route_parsed: {
        totalDistance: 0,
        totalElevation: 0,
        totalElevationLoss: 0,
        sourceType: "gpx",
        title: null,
      },
      stages_computed: { stages: [] },
      weather_fetched: { stages: [] },
      pois_scanned: {
        stageIndex: 0,
        resupply: {
          foodAtLunch: [],
          waterMorning: null,
          waterAfternoon: null,
          foodAtArrival: [],
        },
      },
      accommodations_found: { stageIndex: 0, accommodations: [] },
      events_found: { stageIndex: 0, events: [] },
      supply_timeline: { stageIndex: 0, markers: [] },
      terrain_alerts: { alertsByStage: {} },
      calendar_alerts: { alerts: [] },
      wind_alerts: { alerts: [] },
      bike_shop_alerts: { alerts: [] },
      water_point_alerts: { alerts: [], waterPointsByStage: [] },
      health_service_alerts: { alerts: [] },
      cultural_poi_alerts: { alerts: [] },
      railway_station_alerts: { alerts: [] },
      border_crossing_alerts: { alerts: [] },
      ferry_alerts: { alerts: [] },
      ford_alerts: { alerts: [] },
      route_segment_recalculated: {
        stageIndex: 0,
        reason: "",
        distance: 0,
        elevationGain: 0,
        duration: 0,
        coordinates: [],
      },
      trip_complete: { computationStatus: {} },
      computation_step_completed: {
        step: "",
        category: "route",
        completed: 0,
        total: 0,
      },
      trip_ready: { stages: [], computationStatus: {} },
      stage_updated: { stageIndex: 0, stage: enriched() },
      validation_error: { code: "", message: "" },
      computation_error: { computation: "", message: "", retryable: false },
    };

    for (const type of MERCURE_EVENT_TYPES) {
      const event = { type, data: stubData[type] } as MercureEvent;
      const next = reduceMercureEvent(baseState(), event);
      expect(
        Array.isArray(next.stages),
        `reducer returned no state for ${type}`,
      ).toBe(true);
    }
  });
});
