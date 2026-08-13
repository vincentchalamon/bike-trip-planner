// Pure SSE reconciliation reducers shared by the web and mobile stores (#1013).
// Extracted verbatim (same semantics) from pwa/src/store/trip-store.ts so both
// platforms compose the identical logic and each store stays a thin wrapper
// (dispatch event -> pure reducer -> set). No Zustand/Immer/dayjs dependency.
//
// The race/edge behaviour these encode is covered by characterization tests in
// reconciliation.test.ts and traces back to #840 (concurrency token / stale
// recomputing indices), #649 (client-only field preservation on a stable
// endpoint) and #787 (label preservation on a raw resync).

import { DEFAULT_ACCOMMODATION_RADIUS_KM } from "./accommodation-constants";
import type { EnrichedStagePayload, MercureEvent, StagePayload } from "./mercure";
import type { AlertData, StageData } from "./schemas";

/** A stage alert carrying the client-only `_group` tag added by the store. */
export type StageAlert = AlertData & { _group?: string };

/**
 * Convert an enriched stage wire payload (from `trip_ready` / `stage_updated`)
 * into a {@link StageData} for the store. Supplies defaults for the client-only
 * fields the backend does not serialize (reverse-geocoded labels, radius, supply
 * timeline). Alerts are tagged with the producing group ("terrain") so a later
 * terrain_alerts event replaces rather than duplicates them (#794/#649). Shared
 * by the web and mobile stores so the mapping never diverges (#1014).
 */
export function enrichedPayloadToStageData(
  payload: EnrichedStagePayload,
): StageData {
  return {
    dayNumber: payload.dayNumber,
    distance: payload.distance,
    elevation: payload.elevation,
    elevationLoss: payload.elevationLoss,
    startPoint: payload.startPoint,
    endPoint: payload.endPoint,
    geometry: payload.geometry,
    label: payload.label,
    startLabel: null,
    endLabel: null,
    weather: payload.weather,
    alerts: (payload.alerts ?? []).map((a) => ({ ...a, _group: "terrain" })),
    pois: payload.pois,
    accommodations: payload.accommodations,
    selectedAccommodation: payload.selectedAccommodation,
    accommodationSearchRadiusKm: DEFAULT_ACCOMMODATION_RADIUS_KM,
    isRestDay: payload.isRestDay ?? false,
    supplyTimeline: [],
    events: payload.events ?? [],
  };
}

function sameStart(prev: StageData, incoming: StageData): boolean {
  return (
    prev.startPoint.lat === incoming.startPoint.lat &&
    prev.startPoint.lon === incoming.startPoint.lon
  );
}

function sameEnd(prev: StageData, incoming: StageData): boolean {
  return (
    prev.endPoint.lat === incoming.endPoint.lat &&
    prev.endPoint.lon === incoming.endPoint.lon
  );
}

/**
 * Raw re-hydrate / resync replace (store `setStages`). Preserves client-only
 * reverse-geocoded labels when the endpoint has not moved AND the incoming
 * payload lacks a label (backend has not persisted it yet) — so a resync does
 * not blank labels the client just resolved for a Komoot trip (#787/#649).
 * Note the precedence: incoming wins, prev is only the fallback.
 */
export function reconcileResync(
  existing: StageData[],
  incoming: StageData[],
): StageData[] {
  return incoming.map((stage, i) => {
    const prev = existing[i];
    const startMatch = prev && sameStart(prev, stage);
    const endMatch = prev && sameEnd(prev, stage);
    return {
      ...stage,
      startLabel: startMatch
        ? (stage.startLabel ?? prev.startLabel)
        : stage.startLabel,
      endLabel: endMatch ? (stage.endLabel ?? prev.endLabel) : stage.endLabel,
    };
  });
}

/**
 * Mode 1 terminal `trip_ready` reconciliation (store `applyTripReady`). When a
 * stage endpoint is stable, preserve the client-only fields that arrive via
 * their own earlier SSE events and can be absent/empty from the terminal
 * payload: reverse-geocoded labels, the UI-only accommodation radius, the
 * already-set supply timeline, and non-empty accommodations / selection /
 * alerts / events (#649). Here prev wins over incoming for labels.
 */
export function reconcileTripReady(
  existing: StageData[],
  incoming: StageData[],
): StageData[] {
  return incoming.map((stage, i) => {
    const prev = existing[i];
    const endMatch = prev && sameEnd(prev, stage);
    const startMatch = prev && sameStart(prev, stage);
    return {
      ...stage,
      startLabel: startMatch
        ? (prev.startLabel ?? stage.startLabel)
        : stage.startLabel,
      endLabel: endMatch ? (prev.endLabel ?? stage.endLabel) : stage.endLabel,
      accommodationSearchRadiusKm: endMatch
        ? (prev.accommodationSearchRadiusKm ?? DEFAULT_ACCOMMODATION_RADIUS_KM)
        : DEFAULT_ACCOMMODATION_RADIUS_KM,
      supplyTimeline: prev?.supplyTimeline ?? [],
      accommodations: endMatch
        ? prev.accommodations.length > 0
          ? prev.accommodations
          : stage.accommodations
        : stage.accommodations,
      selectedAccommodation: endMatch
        ? (prev.selectedAccommodation ?? stage.selectedAccommodation)
        : stage.selectedAccommodation,
      alerts: endMatch
        ? prev.alerts.length > 0
          ? prev.alerts
          : stage.alerts
        : stage.alerts,
      events: endMatch
        ? prev.events.length > 0
          ? prev.events
          : stage.events
        : stage.events,
    };
  });
}

/** Result of {@link reconcileStageUpdate}. */
export interface StageUpdateResult {
  stages: StageData[];
  /**
   * True when the update appended a brand-new trailing stage (a last-stage
   * distance reduction that split off a new day on the backend, #840). The
   * caller must then extend the trip's end date by one day.
   */
  appendedTrailingStage: boolean;
}

/**
 * Mode 2 per-stage `stage_updated` reconciliation (store `applyStageUpdate`).
 * Preserves client-only fields on a stable endpoint (labels, radius, supply
 * timeline, accommodations + selection, events) and merges alerts so a
 * terrain-only reroute payload does not blank the separately-scanned
 * cultural-POI recommendations (#649). A `stage_updated` at exactly
 * `stages.length` appends the split-off trailing day (#840); a larger index is
 * a stale/obsolete event and is ignored.
 */
export function reconcileStageUpdate(
  existing: StageData[],
  stageIndex: number,
  incoming: StageData,
): StageUpdateResult {
  const prev = existing[stageIndex];
  if (!prev) {
    if (stageIndex === existing.length) {
      return {
        stages: [...existing, incoming],
        appendedTrailingStage: true,
      };
    }
    return { stages: existing, appendedTrailingStage: false };
  }

  const endMatch = sameEnd(prev, incoming);
  const startMatch = sameStart(prev, incoming);

  const reconciled: StageData = {
    ...incoming,
    startLabel: startMatch
      ? (prev.startLabel ?? incoming.startLabel)
      : incoming.startLabel,
    endLabel: endMatch
      ? (prev.endLabel ?? incoming.endLabel)
      : incoming.endLabel,
    accommodationSearchRadiusKm: endMatch
      ? (prev.accommodationSearchRadiusKm ?? DEFAULT_ACCOMMODATION_RADIUS_KM)
      : DEFAULT_ACCOMMODATION_RADIUS_KM,
    supplyTimeline: prev.supplyTimeline,
    accommodations:
      endMatch && prev.accommodations.length > 0
        ? prev.accommodations
        : incoming.accommodations,
    selectedAccommodation: endMatch
      ? (prev.selectedAccommodation ?? incoming.selectedAccommodation)
      : incoming.selectedAccommodation,
    alerts:
      endMatch && prev.alerts.length > 0
        ? prev.alerts
        : [
            ...prev.alerts.filter((a) => a.source === "cultural_poi"),
            ...incoming.alerts.filter((a) => a.source !== "cultural_poi"),
          ],
    events: endMatch && prev.events.length > 0 ? prev.events : incoming.events,
  };

  const stages = existing.slice();
  stages[stageIndex] = reconciled;
  return { stages, appendedTrailingStage: false };
}

/**
 * Drop recomputing markers for indices that no longer exist after the stage
 * array changed length, so a phantom index never holds the `processing`
 * overlay open forever (#840). Only ever removes indices, so when nothing is
 * stale it returns the SAME set reference — matching the old in-place
 * `.delete()` semantics, so a store selector keyed on `recomputingStages`
 * (Object.is) does not re-render on every resync/structural edit.
 */
export function pruneStaleRecomputing(
  stageCount: number,
  recomputing: Set<number>,
): Set<number> {
  const next = new Set<number>();
  for (const i of recomputing) {
    if (i < stageCount) next.add(i);
  }
  return next.size === recomputing.size ? recomputing : next;
}

/**
 * Drop date-derived calendar nudges from every stage after a structural edit
 * that shifts `dayNumber`s. These alerts are keyed to a stage's date but ride
 * along on the shifted object, so they would otherwise land on a day that is no
 * longer a Sunday; CheckCalendar recomputes and republishes them. Geographic
 * groups stay valid (endpoints do not move on a rest-day insert). Returns new
 * stages.
 */
export function dropStaleDateAlerts(stages: StageData[]): StageData[] {
  return stages.map((stage) => ({
    ...stage,
    alerts: (stage.alerts as StageAlert[]).filter(
      (a) => a._group !== "calendar",
    ),
  }));
}

// ---------------------------------------------------------------------------
// Full Mercure event reconciliation (#1030)
//
// `reduceMercureEvent` is the pure, platform-agnostic mirror of the web hook's
// `dispatchEvent` router (pwa/src/hooks/use-mercure.ts). Given the current
// reconcilable state and one SSE event it returns the next state, composing the
// characterization reducers above (resync / trip_ready / stage_updated /
// prune). Both stores are meant to feed their SSE stream through this single
// function so web and mobile never diverge; the drift guard test asserts every
// event the web hook handles is covered here.
//
// UI-only side effects the web hook also performs stay OUT of core by design:
// toast notifications, block/processing spinners (ADR-043), reverse-geocode
// label resolution, and the transient `stageDiffs` highlight. Trip-level date
// bookkeeping (endDate on a trailing-day append) also stays a store concern,
// as it already is on both platforms.
// ---------------------------------------------------------------------------

/**
 * The slice of store state that Mercure events reconcile. A superset of the
 * fields every current event touches: route totals + source/title metadata,
 * the stage array, the computation-status map, and the set of stage indices
 * still recomputing (whose overlay some terminal events clear).
 */
export interface ReconciledState {
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  sourceType: string | null;
  title: string | null;
  stages: StageData[];
  computationStatus: Record<string, string>;
  recomputingStages: Set<number>;
}

/** Replace `stages[index]` via `patch`; returns the same array if out of range. */
function patchStage(
  stages: StageData[],
  index: number,
  patch: (stage: StageData) => StageData,
): StageData[] {
  const stage = stages[index];
  if (!stage) return stages;
  const next = stages.slice();
  next[index] = patch(stage);
  return next;
}

/**
 * Replace the alerts belonging to `group` on one stage, preserving alerts from
 * every other producing group. Mirrors the store's `updateStageAlerts` (#649):
 * grouped, incremental alert updates that never blank another analyzer's output.
 */
function replaceStageAlerts(
  stages: StageData[],
  index: number,
  alerts: AlertData[],
  group: string,
): StageData[] {
  return patchStage(stages, index, (stage) => {
    const kept = (stage.alerts as StageAlert[]).filter(
      (a) => a._group !== group,
    );
    const tagged: StageAlert[] = alerts.map((a) => ({ ...a, _group: group }));
    return { ...stage, alerts: [...kept, ...tagged] };
  });
}

/** Fold a per-stage-index alert map onto the stage array under one group tag. */
function applyGroupedAlerts(
  stages: StageData[],
  grouped: Map<number, AlertData[]>,
  group: string,
): StageData[] {
  let next = stages;
  for (const [index, alerts] of grouped) {
    next = replaceStageAlerts(next, index, alerts, group);
  }
  return next;
}

/** Group + normalize a flat alert list keyed by `stageIndex` into `AlertData`. */
function groupAlerts<T extends { stageIndex: number }>(
  alerts: T[],
  toAlert: (alert: T) => AlertData,
): Map<number, AlertData[]> {
  const grouped = new Map<number, AlertData[]>();
  for (const alert of alerts) {
    const bucket = grouped.get(alert.stageIndex) ?? [];
    bucket.push(toAlert(alert));
    grouped.set(alert.stageIndex, bucket);
  }
  return grouped;
}

/**
 * `stages_computed` merge (legacy progressive path). A partial update
 * (`affectedIndices`) preserves derived data for untouched stages and resets it
 * for affected/new ones (keeping alerts + accommodations until their follow-up
 * events land, #649); a full replace preserves labels/accommodations/radius on
 * stages whose endpoints did not move. Verbatim from `use-mercure.ts`.
 */
function reconcileStagesComputed(
  existing: StageData[],
  data: { stages: StagePayload[]; affectedIndices?: number[] },
): StageData[] {
  const { affectedIndices } = data;

  if (affectedIndices && affectedIndices.length > 0 && existing.length > 0) {
    const affected = new Set(affectedIndices);
    return data.stages.map((s, i) => {
      const prev = existing[i];
      if (prev && !affected.has(i)) {
        return {
          ...prev,
          dayNumber: s.dayNumber,
          distance: s.distance,
          elevation: s.elevation,
          elevationLoss: s.elevationLoss ?? 0,
          startPoint: s.startPoint,
          endPoint: s.endPoint,
          geometry: s.geometry ?? prev.geometry,
          label: s.label ?? prev.label,
        };
      }
      return {
        ...s,
        elevationLoss: s.elevationLoss ?? 0,
        geometry: s.geometry ?? [],
        label: s.label ?? null,
        isRestDay: s.isRestDay ?? false,
        startLabel: null,
        endLabel: null,
        weather: null,
        alerts: prev?.alerts ?? [],
        pois: [],
        supplyTimeline: [],
        events: [],
        accommodations: prev?.accommodations ?? [],
        selectedAccommodation: prev?.selectedAccommodation ?? null,
        accommodationSearchRadiusKm:
          prev?.accommodationSearchRadiusKm ?? DEFAULT_ACCOMMODATION_RADIUS_KM,
      };
    });
  }

  return data.stages.map((s, i) => {
    const prev = existing[i];
    const endMatch =
      prev &&
      prev.endPoint.lat === s.endPoint.lat &&
      prev.endPoint.lon === s.endPoint.lon;
    const startMatch =
      prev &&
      prev.startPoint.lat === s.startPoint.lat &&
      prev.startPoint.lon === s.startPoint.lon;
    return {
      ...s,
      elevationLoss: s.elevationLoss ?? 0,
      geometry: s.geometry ?? [],
      label: s.label ?? null,
      isRestDay: s.isRestDay ?? false,
      startLabel: startMatch ? prev.startLabel : null,
      endLabel: endMatch ? prev.endLabel : null,
      weather: null,
      alerts: [],
      pois: [],
      supplyTimeline: [],
      events: [],
      accommodations: endMatch ? prev.accommodations : [],
      accommodationSearchRadiusKm: endMatch
        ? (prev.accommodationSearchRadiusKm ?? DEFAULT_ACCOMMODATION_RADIUS_KM)
        : DEFAULT_ACCOMMODATION_RADIUS_KM,
    };
  });
}

/** Empty recomputing set (terminal events clear the overlay). */
const NO_RECOMPUTING: ReadonlySet<number> = new Set<number>();

/**
 * Reconcile one Mercure SSE event into the next {@link ReconciledState}. Pure:
 * never mutates its inputs, returns a new state (data-less/no-op events return
 * the same reference). The `default` branch is compile-time exhaustive — adding
 * a new `MercureEvent` variant without a case here fails to type-check.
 */
export function reduceMercureEvent(
  state: ReconciledState,
  event: MercureEvent,
): ReconciledState {
  switch (event.type) {
    case "route_parsed":
      return {
        ...state,
        totalDistance: event.data.totalDistance,
        totalElevation: event.data.totalElevation,
        totalElevationLoss: event.data.totalElevationLoss,
        sourceType: event.data.sourceType,
        title: event.data.title ?? state.title,
      };

    case "stages_computed": {
      // Mirror the store's setStages: run the resync label-preservation pass on
      // the merged array, then drop recompute markers that fell out of bounds.
      const merged = reconcileStagesComputed(state.stages, event.data);
      const stages = reconcileResync(state.stages, merged);
      return {
        ...state,
        stages,
        recomputingStages: pruneStaleRecomputing(
          stages.length,
          state.recomputingStages,
        ),
      };
    }

    case "weather_fetched": {
      let stages = state.stages;
      for (const w of event.data.stages) {
        const weather = w.weather;
        if (!weather) continue;
        const index = stages.findIndex((s) => s.dayNumber === w.dayNumber);
        if (index !== -1) {
          stages = patchStage(stages, index, (s) => ({ ...s, weather }));
        }
      }
      return stages === state.stages ? state : { ...state, stages };
    }

    case "pois_scanned": {
      let stages = patchStage(state.stages, event.data.stageIndex, (s) => ({
        ...s,
        pois: event.data.pois,
      }));
      if (event.data.alerts && event.data.alerts.length > 0) {
        stages = replaceStageAlerts(
          stages,
          event.data.stageIndex,
          event.data.alerts,
          "pois",
        );
      }
      return { ...state, stages };
    }

    case "supply_timeline":
      return {
        ...state,
        stages: patchStage(state.stages, event.data.stageIndex, (s) => ({
          ...s,
          supplyTimeline: event.data.markers,
        })),
      };

    case "accommodations_found": {
      const { stageIndex, accommodations, searchRadiusKm } = event.data;
      let stages = patchStage(state.stages, stageIndex, (s) => ({
        ...s,
        // Do not clobber a rider's picked accommodation (store parity).
        accommodations: s.selectedAccommodation ? s.accommodations : accommodations,
        accommodationSearchRadiusKm:
          searchRadiusKm !== undefined
            ? searchRadiusKm
            : s.accommodationSearchRadiusKm,
      }));
      if (event.data.alerts && event.data.alerts.length > 0) {
        stages = replaceStageAlerts(
          stages,
          stageIndex,
          event.data.alerts,
          "accommodations",
        );
      }
      return { ...state, stages };
    }

    case "events_found":
      return {
        ...state,
        stages: patchStage(state.stages, event.data.stageIndex, (s) => ({
          ...s,
          events: event.data.events,
        })),
      };

    case "terrain_alerts": {
      let stages = state.stages;
      for (const [indexStr, alerts] of Object.entries(
        event.data.alertsByStage,
      )) {
        const index = Number(indexStr);
        if (!Number.isNaN(index)) {
          stages = replaceStageAlerts(stages, index, alerts, "terrain");
        }
      }
      return { ...state, stages };
    }

    case "calendar_alerts": {
      // Full replacement: clear the group on EVERY stage first so a stage that
      // dropped out of the new set keeps no stale nudge (recette Sunday bug).
      const cleared = state.stages.map((s) => ({
        ...s,
        alerts: (s.alerts as StageAlert[]).filter((a) => a._group !== "calendar"),
      }));
      const grouped = groupAlerts(event.data.alerts, (a) => ({
        code: a.code,
        type: a.type as AlertData["type"],
        message: a.message,
        lat: null,
        lon: null,
      }));
      return { ...state, stages: applyGroupedAlerts(cleared, grouped, "calendar") };
    }

    case "wind_alerts":
      return {
        ...state,
        stages: replaceStageAlerts(state.stages, 0, event.data.alerts, "wind"),
      };

    case "bike_shop_alerts": {
      const grouped = groupAlerts(event.data.alerts, (a) => ({
        code: a.code,
        type: a.type as "nudge",
        message: a.message,
        lat: null,
        lon: null,
      }));
      return {
        ...state,
        stages: applyGroupedAlerts(state.stages, grouped, "bike_shop"),
      };
    }

    case "water_point_alerts": {
      const grouped = groupAlerts(event.data.alerts, (a) => ({
        code: a.code,
        type: a.type as "nudge",
        message: a.message,
        lat: null,
        lon: null,
        source: "water_point",
      }));
      return {
        ...state,
        stages: applyGroupedAlerts(state.stages, grouped, "water_point"),
      };
    }

    case "health_service_alerts": {
      const grouped = groupAlerts(event.data.alerts, (a) => ({
        code: a.code,
        type: a.type as "nudge",
        message: a.message,
      }));
      return {
        ...state,
        stages: applyGroupedAlerts(state.stages, grouped, "health_service"),
      };
    }

    case "cultural_poi_alerts": {
      const grouped = groupAlerts(event.data.alerts, (a) => ({
        code: a.code,
        type: "nudge" as const,
        message: a.message,
        lat: a.lat,
        lon: a.lon,
        source: "cultural_poi",
        poiName: a.poiName,
        poiType: a.poiType,
        poiLat: a.poiLat,
        poiLon: a.poiLon,
        distanceFromRoute: a.distanceFromRoute,
        description: a.description,
        openingHours: a.openingHours,
        estimatedPrice: a.estimatedPrice,
        imageUrl: a.imageUrl,
        wikidataId: a.wikidataId,
        wikipediaUrl: a.wikipediaUrl,
      }));
      return {
        ...state,
        stages: applyGroupedAlerts(state.stages, grouped, "cultural_poi"),
      };
    }

    case "railway_station_alerts": {
      const grouped = groupAlerts(event.data.alerts, (a) => ({
        code: a.code,
        type: "nudge" as const,
        message: a.message,
        lat: a.lat ?? null,
        lon: a.lon ?? null,
        source: "railway_station",
        ...(a.action ? { action: a.action } : {}),
      }));
      return {
        ...state,
        stages: applyGroupedAlerts(state.stages, grouped, "railway_station"),
      };
    }

    case "border_crossing_alerts": {
      const grouped = groupAlerts(event.data.alerts, (a) => ({
        code: a.code,
        type: a.type,
        message: a.message,
        lat: a.lat,
        lon: a.lon,
        source: "border_crossing",
        action: a.action,
      }));
      return {
        ...state,
        stages: applyGroupedAlerts(state.stages, grouped, "border_crossing"),
      };
    }

    case "ferry_alerts": {
      const grouped = groupAlerts(event.data.alerts, (a) => ({
        code: a.code,
        type: a.type,
        message: a.message,
        lat: a.lat,
        lon: a.lon,
        source: "ferry",
        action: {
          kind: a.action.kind,
          label: a.action.label,
          payload: a.action.payload,
        },
      }));
      return { ...state, stages: applyGroupedAlerts(state.stages, grouped, "ferry") };
    }

    case "ford_alerts": {
      const grouped = groupAlerts(event.data.alerts, (a) => ({
        code: a.code,
        type: a.type,
        message: a.message,
        lat: a.lat,
        lon: a.lon,
        source: "ford",
        action: {
          kind: a.action.kind,
          label: a.action.label,
          payload: a.action.payload,
        },
      }));
      return { ...state, stages: applyGroupedAlerts(state.stages, grouped, "ford") };
    }

    case "route_segment_recalculated": {
      let stages = patchStage(state.stages, event.data.stageIndex, (s) => ({
        ...s,
        distance: event.data.distance / 1000, // metres → km
        elevation: event.data.elevationGain,
        geometry: event.data.coordinates,
      }));
      stages = replaceStageAlerts(stages, event.data.stageIndex, [], "cultural_poi");
      return { ...state, stages, recomputingStages: new Set(NO_RECOMPUTING) };
    }

    case "trip_complete":
      return {
        ...state,
        computationStatus: event.data.computationStatus,
        recomputingStages: new Set(NO_RECOMPUTING),
      };

    case "computation_step_completed":
      // Mode 1 progress tick only — no data reconciliation (ADR-043).
      return state;

    case "trip_ready": {
      const incoming = event.data.stages.map(enrichedPayloadToStageData);
      return {
        ...state,
        stages: reconcileTripReady(state.stages, incoming),
        computationStatus: event.data.computationStatus,
        recomputingStages: new Set(NO_RECOMPUTING),
      };
    }

    case "stage_updated": {
      const incoming = enrichedPayloadToStageData(event.data.stage);
      const { stages } = reconcileStageUpdate(
        state.stages,
        event.data.stageIndex,
        incoming,
      );
      const recomputingStages = new Set(state.recomputingStages);
      recomputingStages.delete(event.data.stageIndex);
      return { ...state, stages, recomputingStages };
    }

    case "validation_error":
      return { ...state, recomputingStages: new Set(NO_RECOMPUTING) };

    case "computation_error":
      return event.data.retryable
        ? state
        : { ...state, recomputingStages: new Set(NO_RECOMPUTING) };

    default: {
      // Exhaustiveness: every MercureEvent variant must have a case above.
      const _exhaustive: never = event;
      return _exhaustive;
    }
  }
}
