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
import type {
  EnrichedStagePayload,
  MercureEvent,
  StagePayload,
} from "./mercure";
import { EMPTY_RESUPPLY } from "./schemas";
import type { AlertData, StageData } from "./schemas";

/**
 * A stage alert, carrying the group that owns it.
 *
 * The tag used to be a client-only `_group` the store bolted on at hydration, because the
 * server did not say which producer an alert came from. It does now (ADR-068), so there is
 * one field instead of two and nothing left to guess.
 */
export type StageAlert = AlertData;

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
    id: payload.stageId,
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
    // The server stamps the owning group on every alert (ADR-068). Tagging them all
    // `terrain` here was safe only while terrain was the one persisted group: with
    // thirteen of them, the first terrain_alerts event would have wiped the other twelve.
    alerts: payload.alerts ?? [],
    resupply: payload.resupply,
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
 * cultural-POI recommendations (#649).
 *
 * The stage is matched by identity. An identifier we do not know is ambiguous on
 * its own — it is either the trailing day a distance edit just split off (#840)
 * or an event from a superseded pacing generation — so `position` decides: at
 * exactly `stages.length` it is the new trailing day and is appended, anywhere
 * else it is stale and ignored.
 */
export function reconcileStageUpdate(
  existing: StageData[],
  stageId: string,
  position: number,
  incoming: StageData,
): StageUpdateResult {
  const index = existing.findIndex((stage) => stage.id === stageId);
  const prev = existing[index];
  if (!prev) {
    if (position === existing.length) {
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
  stages[index] = reconciled;
  return { stages, appendedTrailingStage: false };
}

/**
 * Drop recomputing markers for stages that no longer exist, so a marker never
 * holds the `processing` overlay open forever (#840). Only ever removes entries,
 * so when nothing is stale it returns the SAME set reference — matching the old
 * in-place `.delete()` semantics, so a store selector keyed on
 * `recomputingStages` (Object.is) does not re-render on every resync.
 *
 * Keyed on identity, a marker follows its stage across insertions and moves
 * instead of being stranded on whatever now sits at that position.
 */
export function pruneStaleRecomputing(
  stages: StageData[],
  recomputing: Set<string>,
): Set<string> {
  const alive = new Set(stages.map((stage) => stage.id));
  const next = new Set<string>();
  for (const stageId of recomputing) {
    if (alive.has(stageId)) next.add(stageId);
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
      (a) => a.group !== "calendar",
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
  recomputingStages: Set<string>;
}

/**
 * Replace the stage identified by `stageId` via `patch`; returns the same array
 * when no stage carries that identifier — which is the expected outcome for an
 * event from a superseded pacing generation.
 */
function patchStage(
  stages: StageData[],
  stageId: string,
  patch: (stage: StageData) => StageData,
): StageData[] {
  const index = stages.findIndex((stage) => stage.id === stageId);
  if (index === -1) return stages;
  const next = stages.slice();
  next[index] = patch(next[index]!);
  return next;
}

/**
 * Replace the alerts belonging to `group` on one stage, preserving alerts from
 * every other producing group. Mirrors the store's `updateStageAlerts` (#649):
 * grouped, incremental alert updates that never blank another analyzer's output.
 */
function replaceStageAlerts(
  stages: StageData[],
  stageId: string,
  alerts: AlertData[],
  group: string,
): StageData[] {
  return patchStage(stages, stageId, (stage) => {
    const kept = (stage.alerts as StageAlert[]).filter(
      (a) => a.group !== group,
    );
    // Still tagged here: a live payload carries its own group, but stamping it keeps the
    // reducer correct for one built before the field travelled.
    const tagged: StageAlert[] = alerts.map((a) => ({ ...a, group }));
    return { ...stage, alerts: [...kept, ...tagged] };
  });
}

/** Fold a per-stage alert map onto the stage array under one group tag. */
function applyGroupedAlerts(
  stages: StageData[],
  grouped: Map<string, AlertData[]>,
  group: string,
): StageData[] {
  let next = stages;
  for (const [stageId, alerts] of grouped) {
    next = replaceStageAlerts(next, stageId, alerts, group);
  }
  return next;
}

/**
 * Group + normalize a flat alert list keyed by `stageId` into `AlertData`.
 *
 * The structural constraint is deliberate: it makes every producer of a grouped
 * alert list fail to compile until it carries the identifier.
 */
function groupAlerts<T extends { stageId: string }>(
  alerts: T[],
  toAlert: (alert: T) => AlertData,
): Map<string, AlertData[]> {
  const grouped = new Map<string, AlertData[]>();
  for (const alert of alerts) {
    const bucket = grouped.get(alert.stageId) ?? [];
    bucket.push(toAlert(alert));
    grouped.set(alert.stageId, bucket);
  }
  return grouped;
}

/**
 * `stages_computed` merge (legacy progressive path). A partial update
 * (`affectedStageIds`) preserves derived data for untouched stages and resets it
 * for affected/new ones (keeping alerts + accommodations until their follow-up
 * events land, #649); a full replace preserves labels/accommodations/radius on
 * stages whose endpoints did not move. Verbatim from `use-mercure.ts`.
 */
function reconcileStagesComputed(
  existing: StageData[],
  data: { stages: StagePayload[]; affectedStageIds?: string[] },
): StageData[] {
  const { affectedStageIds } = data;

  if (affectedStageIds && affectedStageIds.length > 0 && existing.length > 0) {
    const affected = new Set(affectedStageIds);
    return data.stages.map((s, i) => {
      const prev = existing[i];
      if (prev && !affected.has(s.stageId)) {
        return {
          ...prev,
          id: s.stageId,
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
        id: s.stageId,
        elevationLoss: s.elevationLoss ?? 0,
        geometry: s.geometry ?? [],
        label: s.label ?? null,
        isRestDay: s.isRestDay ?? false,
        startLabel: null,
        endLabel: null,
        weather: null,
        alerts: prev?.alerts ?? [],
        resupply: EMPTY_RESUPPLY,
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
      id: s.stageId,
      elevationLoss: s.elevationLoss ?? 0,
      geometry: s.geometry ?? [],
      label: s.label ?? null,
      isRestDay: s.isRestDay ?? false,
      startLabel: startMatch ? prev.startLabel : null,
      endLabel: endMatch ? prev.endLabel : null,
      weather: null,
      alerts: [],
      resupply: EMPTY_RESUPPLY,
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
const NO_RECOMPUTING: ReadonlySet<string> = new Set<string>();

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
          stages,
          state.recomputingStages,
        ),
      };
    }

    case "weather_fetched": {
      let stages = state.stages;
      for (const w of event.data.stages) {
        const weather = w.weather;
        if (!weather) continue;
        const stage = stages.find((s) => s.dayNumber === w.dayNumber);
        if (stage) {
          stages = patchStage(stages, stage.id, (s) => ({ ...s, weather }));
        }
      }
      return stages === state.stages ? state : { ...state, stages };
    }

    case "pois_scanned": {
      let stages = patchStage(state.stages, event.data.stageId, (s) => ({
        ...s,
        resupply: event.data.resupply,
      }));
      // Presence, not emptiness: the handler always sends the key, and an empty list
      // is a result — the scan ran and found nothing, so the previous alerts go.
      if (event.data.alerts) {
        stages = replaceStageAlerts(
          stages,
          event.data.stageId,
          event.data.alerts,
          "pois",
        );
      }
      return { ...state, stages };
    }

    case "supply_timeline":
      return {
        ...state,
        stages: patchStage(state.stages, event.data.stageId, (s) => ({
          ...s,
          supplyTimeline: event.data.markers,
        })),
      };

    case "accommodations_found": {
      const { stageId, accommodations, searchRadiusKm } = event.data;
      let stages = patchStage(state.stages, stageId, (s) => ({
        ...s,
        // Do not clobber a rider's picked accommodation (store parity).
        accommodations: s.selectedAccommodation
          ? s.accommodations
          : accommodations,
        accommodationSearchRadiusKm:
          searchRadiusKm !== undefined
            ? searchRadiusKm
            : s.accommodationSearchRadiusKm,
      }));
      // Presence, not emptiness — see the pois_scanned case above.
      if (event.data.alerts) {
        stages = replaceStageAlerts(
          stages,
          stageId,
          event.data.alerts,
          "accommodations",
        );
      }
      return { ...state, stages };
    }

    case "events_found":
      return {
        ...state,
        stages: patchStage(state.stages, event.data.stageId, (s) => ({
          ...s,
          events: event.data.events,
        })),
      };

    case "terrain_alerts": {
      let stages = state.stages;
      // The map key is a stage identifier now, so there is nothing to parse and
      // nothing that can silently resolve to the wrong stage.
      for (const [stageId, alerts] of Object.entries(
        event.data.alertsByStage,
      )) {
        stages = replaceStageAlerts(stages, stageId, alerts, "terrain");
      }
      return { ...state, stages };
    }

    case "calendar_alerts": {
      // Full replacement: clear the group on EVERY stage first so a stage that
      // dropped out of the new set keeps no stale nudge (recette Sunday bug).
      const cleared = state.stages.map((s) => ({
        ...s,
        alerts: (s.alerts as StageAlert[]).filter(
          (a) => a.group !== "calendar",
        ),
      }));
      const grouped = groupAlerts(event.data.alerts, (a) => ({
        code: a.code,
        type: a.type as AlertData["type"],
        message: a.message,
        lat: null,
        lon: null,
      }));
      return {
        ...state,
        stages: applyGroupedAlerts(cleared, grouped, "calendar"),
      };
    }

    case "wind_alerts":
      // Previously pinned to the first stage, because the event carried no stage
      // reference at all — a message about the whole trip read as if it concerned
      // day one. It names its stages now.
      return {
        ...state,
        stages: applyGroupedAlerts(
          state.stages,
          groupAlerts(event.data.alerts, (a) => ({
            code: a.code,
            type: a.type,
            message: a.message,
            action: a.action,
          })),
          "wind",
        ),
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
      return {
        ...state,
        stages: applyGroupedAlerts(state.stages, grouped, "ferry"),
      };
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
      return {
        ...state,
        stages: applyGroupedAlerts(state.stages, grouped, "ford"),
      };
    }

    case "route_segment_recalculated": {
      let stages = patchStage(state.stages, event.data.stageId, (s) => ({
        ...s,
        distance: event.data.distance / 1000, // metres → km
        elevation: event.data.elevationGain,
        geometry: event.data.coordinates,
      }));
      stages = replaceStageAlerts(
        stages,
        event.data.stageId,
        [],
        "cultural_poi",
      );
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
        event.data.stageId,
        event.data.position,
        incoming,
      );
      const recomputingStages = new Set(state.recomputingStages);
      recomputingStages.delete(event.data.stageId);
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
