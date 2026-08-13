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
import type { EnrichedStagePayload } from "./mercure";
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
