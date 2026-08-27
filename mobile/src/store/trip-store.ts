import { create } from 'zustand';
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import { DEFAULT_ACCOMMODATION_RADIUS_KM } from '@btp/core/constants';
import type { MercureEvent } from '@btp/core/mercure';
import {
  type ReconciledState,
  reduceMercureEvent,
  reconcileStageUpdate,
  reconcileTripReady,
} from '@btp/core/reconciliation';
import type { TripDetail, TripRoute } from '../api/trips';
import { DIFF_TTL_MS, diffStageIndices } from './config-diff';
import { createTemporalStore } from './temporal-middleware';

type ApiStage = NonNullable<TripDetail['stages']>[number];

// Map a persisted /detail stage to the store's StageData shape. Field names
// match the API; client-only fields (labels, radius, supply timeline) get
// defaults. Mirrors the web hydrate in pwa's trip-page.
export function stageDataFromDetail(s: ApiStage): StageData {
  return {
    dayNumber: s.dayNumber ?? 0,
    distance: s.distance ?? 0,
    elevation: s.elevation ?? 0,
    elevationLoss: s.elevationLoss ?? 0,
    startPoint: (s.startPoint as StageData['startPoint']) ?? {
      lat: 0,
      lon: 0,
      ele: 0,
    },
    endPoint: (s.endPoint as StageData['endPoint']) ?? { lat: 0, lon: 0, ele: 0 },
    // The summary carries no geometry (ADR-057): it is hydrated on demand from
    // GET /route (map) and GET /stages/{i}/detail (stage view).
    geometry: [],
    label: s.label ?? null,
    startLabel: s.startLabel ?? null,
    endLabel: s.endLabel ?? null,
    weather: (s.weather as StageData['weather']) ?? null,
    // Tag persisted alerts with their producing group so a later terrain_alerts
    // event replaces rather than duplicates them (mirrors the web hydrate, #794).
    alerts: ((s.alerts as StageData['alerts']) ?? []).map((a) => ({
      ...a,
      _group: 'terrain',
    })),
    resupply: (s.resupply as StageData['resupply']) ?? EMPTY_RESUPPLY,
    accommodations: (s.accommodations as StageData['accommodations']) ?? [],
    selectedAccommodation:
      (s.selectedAccommodation as StageData['selectedAccommodation']) ?? null,
    accommodationSearchRadiusKm: DEFAULT_ACCOMMODATION_RADIUS_KM,
    isRestDay: s.isRestDay ?? false,
    supplyTimeline: [],
    events: [],
  };
}

/**
 * A single user modification accumulated in the batch queue before being sent
 * in one `POST /trips/{id}/recompute` pass. Mirrors the web store's shape and
 * the backend `TripModification` (type ↔ re-dispatched handlers).
 */
export interface Modification {
  /** Zero-based stage index. Null for trip-level changes (dates, pacing). */
  stageIndex: number | null;
  type: 'accommodation' | 'distance' | 'dates' | 'pacing';
  /** Human-readable description for the queue panel. */
  label: string;
}

/** Editable pacing / dates / preferences slice, mirrored from the web store. */
export interface TripConfig {
  startDate: string | null;
  endDate: string | null;
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
  ebikeMode: boolean;
  departureHour: number;
  enabledAccommodationTypes: string[];
}

const DEFAULT_CONFIG: TripConfig = {
  startDate: null,
  endDate: null,
  fatigueFactor: 0.9,
  elevationPenalty: 50,
  maxDistancePerDay: 80,
  averageSpeed: 15,
  ebikeMode: false,
  departureHour: 8,
  enabledAccommodationTypes: [],
};

// Add `days` to a YYYY-MM-DD / ISO date string, returning YYYY-MM-DD. Used for
// the optimistic endDate when a structural edit changes the stage count; the
// authoritative value arrives over SSE. UTC math keeps it timezone-stable.
function addDays(dateStr: string, days: number): string {
  const d = new Date(dateStr);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

// Renumber dayNumbers 1..n after a structural edit.
function renumber(stages: StageData[]): StageData[] {
  return stages.map((stage, i) => ({ ...stage, dayNumber: i + 1 }));
}

// Recompute endDate from startDate + (stageCount - 1) days when a structural
// edit changed the count (each stage spans one calendar day, rest days
// included, recette #649). Returns the patch to apply, or {} without a start.
function endDatePatch(
  startDate: string | null,
  stageCount: number,
): Partial<TripConfig> {
  if (!startDate) return {};
  return { endDate: addDays(startDate, Math.max(0, stageCount - 1)) };
}

interface TripState extends TripConfig {
  tripId: string | null;
  title: string | null;
  // Original import URL (Komoot/Strava/RideWithGPS), surfaced in the share text.
  sourceUrl: string | null;
  stages: StageData[];
  // Trip started (startDate <= today): the backend rejects edits with 423, so the
  // UI disables them. Read from the /detail payload on hydrate.
  isLocked: boolean;
  // Route outside the provisioned coverage area: editing/rerouting is disabled.
  outOfZone: boolean;
  // An SSE recompute is streaming (computation_step events between a modification
  // and the terminal trip_ready/trip_complete). Drives the SseStatusIndicator.
  computing: boolean;
  // Route geometry (split off /detail, ADR-057) has been fetched and merged into
  // the stages. False after a fresh hydrate; set once GET /route lands.
  geometryLoaded: boolean;
  // Accumulated edits not yet sent to /recompute.
  pendingModifications: Modification[];
  // Indices of stages that changed on the last destructive recompute, lit as a
  // transient diff-highlight in the roadbook and auto-cleared after DIFF_TTL_MS.
  stageDiffs: Set<number>;
  // Pre-recompute stage snapshot armed before a destructive config edit; the
  // next trip_ready diffs against it to populate stageDiffs, then clears it.
  diffBaseline: StageData[] | null;
  // Generation counters for the single shared `diffBaseline` slot. Each arm
  // bumps `diffBaselineToken`; each consumed trip_ready bumps
  // `diffConsumedToken`. The baseline is only released once every armed
  // generation has been consumed, so two destructive recomputes armed
  // back-to-back don't let the first trip_ready null the slot out from under
  // the second (which would silently drop its highlight).
  diffBaselineToken: number;
  diffConsumedToken: number;
  loading: boolean;
  error: string | null;
  // Initial load from a /trips/{id}/detail payload.
  hydrate: (tripId: string, detail: TripDetail) => void;
  // Mode 1 terminal event: reconcile the whole trip via the shared core reducer.
  applyTripReady: (stages: StageData[]) => void;
  // Mode 2 per-stage event: reconcile a single slice via the shared core reducer.
  applyStageUpdate: (index: number, stage: StageData) => void;
  // Reconcile one SSE enrichment/segment event through the shared core reducer
  // (weather, POIs, accommodations, alerts, route_segment_recalculated, …) that
  // trip_ready/stage_updated do not cover, so mobile reflects them live (ADR-055).
  applyMercureEvent: (event: MercureEvent) => void;
  // Merge the on-demand route geometry (GET /route) into the matching stages.
  applyRoute: (route: TripRoute) => void;
  // Merge one stage's on-demand geometry (GET /stages/{index}/detail) into that
  // stage — the per-stage detail screen only needs ~300 points, not the whole route.
  applyStageDetail: (index: number, geometry: StageData['geometry']) => void;
  // Replace the whole stage list (optimistic rollback restores a snapshot).
  setStages: (stages: StageData[]) => void;
  // Patch any subset of the editable config slice.
  setConfig: (patch: Partial<TripConfig>) => void;
  setTitle: (title: string) => void;
  setIsLocked: (isLocked: boolean) => void;
  setOutOfZone: (outOfZone: boolean) => void;
  setComputing: (computing: boolean) => void;
  // Optimistic structural edits (mirror the web store). The authoritative state
  // arrives via SSE reconciliation; on API failure the caller restores the
  // pre-edit snapshot via setStages.
  deleteStageOptimistic: (index: number) => void;
  insertRestDayOptimistic: (afterIndex: number) => void;
  insertStageOptimistic: (afterIndex: number, placeholder: StageData) => void;
  moveStageOptimistic: (fromIndex: number, toIndex: number) => void;
  selectAccommodationOptimistic: (
    stageIndex: number,
    accIndex: number,
    nextStageIndex: number | null,
  ) => void;
  deselectAccommodationOptimistic: (stageIndex: number) => void;
  // Arm a diff baseline before a destructive edit (dates/pacing): the next
  // trip_ready compares against it to light the changed stages.
  armConfigDiff: () => void;
  // Cancel an armed generation whose commit failed (no trip_ready will follow):
  // token-aware so it doesn't null the shared baseline out from under a second
  // still-pending generation.
  disarmConfigDiff: () => void;
  // Clear the transient diff-highlight (called by the auto-expiry timer).
  clearStageDiffs: () => void;
  // Batch queue: replace a duplicate (same type + stageIndex) rather than append.
  queueModification: (modification: Modification) => void;
  cancelAllModifications: () => void;
  clearPendingModifications: () => void;
  setStatus: (patch: { loading?: boolean; error?: string | null }) => void;
  reset: () => void;
}

// Thin RN store: it holds StageData + the editable config and delegates every
// reconciliation to the pure reducers in @btp/core, so the web and mobile stores
// share one source of truth (#1014). Optimistic structural edits mirror the web
// store's renumber/date bookkeeping so a mutation reflects immediately; the
// mutation runners (mutations.ts) drive the API call + rollback (#1031).
export const useTripStore = create<TripState>((set, get) => ({
  tripId: null,
  title: null,
  sourceUrl: null,
  stages: [],
  isLocked: false,
  outOfZone: false,
  computing: false,
  geometryLoaded: false,
  pendingModifications: [],
  stageDiffs: new Set<number>(),
  diffBaseline: null,
  diffBaselineToken: 0,
  diffConsumedToken: 0,
  loading: true,
  error: null,
  ...DEFAULT_CONFIG,
  hydrate: (tripId, detail) => {
    // A fresh trip has no undoable history (mirrors the web clearTrip).
    useTripTemporalStore.getState().clear();
    set({
      tripId,
      title: detail.title ?? null,
      sourceUrl: detail.sourceUrl ?? null,
      stages: (detail.stages ?? []).map(stageDataFromDetail),
      isLocked: detail.isLocked ?? false,
      outOfZone: detail.outOfZone ?? false,
      computing: false,
      geometryLoaded: false,
      startDate: detail.startDate ?? null,
      endDate: detail.endDate ?? null,
      fatigueFactor: detail.fatigueFactor ?? DEFAULT_CONFIG.fatigueFactor,
      elevationPenalty:
        detail.elevationPenalty ?? DEFAULT_CONFIG.elevationPenalty,
      maxDistancePerDay:
        detail.maxDistancePerDay ?? DEFAULT_CONFIG.maxDistancePerDay,
      averageSpeed: detail.averageSpeed ?? DEFAULT_CONFIG.averageSpeed,
      ebikeMode: detail.ebikeMode ?? DEFAULT_CONFIG.ebikeMode,
      departureHour: detail.departureHour ?? DEFAULT_CONFIG.departureHour,
      enabledAccommodationTypes: detail.enabledAccommodationTypes ?? [],
      pendingModifications: [],
      stageDiffs: new Set<number>(),
      diffBaseline: null,
      diffBaselineToken: 0,
      diffConsumedToken: 0,
      loading: false,
      error: null,
    });
  },
  applyTripReady: (stages) =>
    set((state) => {
      const reconciled = reconcileTripReady(state.stages, stages);
      // No armed baseline → an ordinary recompute, no diff-highlight.
      if (!state.diffBaseline) return { stages: reconciled };
      const stageDiffs = diffStageIndices(state.diffBaseline, reconciled);
      if (stageDiffs.size > 0) {
        // Key the auto-expiry to this exact diff set: a later destructive
        // recompute within the TTL replaces stageDiffs, and this stale timer
        // must not wipe the fresher highlights.
        setTimeout(() => {
          if (get().stageDiffs === stageDiffs) get().clearStageDiffs();
        }, DIFF_TTL_MS);
      }
      // Release the shared baseline only once every armed generation has been
      // consumed. While earlier generations remain (a second destructive
      // recompute was armed before this trip_ready), keep the baseline so the
      // later trip_ready still diffs and doesn't silently drop its highlight.
      const consumed = state.diffConsumedToken + 1;
      if (consumed >= state.diffBaselineToken) {
        return {
          stages: reconciled,
          stageDiffs,
          diffBaseline: null,
          diffBaselineToken: 0,
          diffConsumedToken: 0,
        };
      }
      return { stages: reconciled, stageDiffs, diffConsumedToken: consumed };
    }),
  applyStageUpdate: (index, stage) =>
    set((state) => {
      const { stages, appendedTrailingStage } = reconcileStageUpdate(
        state.stages,
        index,
        stage,
      );
      // A trailing-day split (#840) grows the stage count by one, so the trip's
      // end date must extend by a day too (core delegates this bookkeeping to the
      // store — reconciliation.ts). Same patch as the optimistic structural edits.
      return appendedTrailingStage
        ? { stages, ...endDatePatch(state.startDate, stages.length) }
        : { stages };
    }),
  applyMercureEvent: (event) =>
    set((state) => {
      // Feed the reducer the current stages/title; the trip-level fields it also
      // tracks (totals, computationStatus, recomputingStages) are not consumed on
      // mobile — the summary derives totals from stages, and the computing spinner
      // stays in setComputing — so they start empty each event and their output is
      // dropped. Only stages (+ title when set) are written back.
      const prev: ReconciledState = {
        totalDistance: null,
        totalElevation: null,
        totalElevationLoss: null,
        sourceType: null,
        title: state.title,
        stages: state.stages,
        computationStatus: {},
        recomputingStages: new Set(),
      };
      const next = reduceMercureEvent(prev, event);
      return next.title !== null
        ? { stages: next.stages, title: next.title }
        : { stages: next.stages };
    }),
  applyRoute: (route) =>
    set((state) => {
      const geometryByDay = new Map(
        (route.stages ?? []).map((s) => [s.dayNumber, s.geometry]),
      );
      return {
        geometryLoaded: true,
        stages: state.stages.map((stage) => {
          const geometry = geometryByDay.get(stage.dayNumber);
          return geometry
            ? { ...stage, geometry: geometry as StageData['geometry'] }
            : stage;
        }),
      };
    }),
  applyStageDetail: (index, geometry) =>
    set((state) => {
      const stage = state.stages[index];
      if (!stage) return {};
      const stages = [...state.stages];
      stages[index] = { ...stage, geometry };
      return { stages };
    }),
  setStages: (stages) => set({ stages }),
  setConfig: (patch) => set(patch),
  setTitle: (title) => set({ title }),
  setIsLocked: (isLocked) => set({ isLocked }),
  setOutOfZone: (outOfZone) => set({ outOfZone }),
  setComputing: (computing) => set({ computing }),
  deleteStageOptimistic: (index) =>
    set((state) => {
      const stages = renumber(state.stages.filter((_, i) => i !== index));
      return { stages, ...endDatePatch(state.startDate, stages.length) };
    }),
  insertRestDayOptimistic: (afterIndex) =>
    set((state) => {
      const after = state.stages[afterIndex];
      if (!after) return {};
      const restDay: StageData = {
        dayNumber: afterIndex + 2,
        distance: 0,
        elevation: 0,
        elevationLoss: 0,
        startPoint: { ...after.endPoint },
        endPoint: { ...after.endPoint },
        geometry: [],
        label: null,
        startLabel: after.endLabel ?? null,
        endLabel: after.endLabel ?? null,
        weather: null,
        alerts: [],
        resupply: EMPTY_RESUPPLY,
        accommodations: [],
        selectedAccommodation: null,
        accommodationSearchRadiusKm: DEFAULT_ACCOMMODATION_RADIUS_KM,
        isRestDay: true,
        supplyTimeline: [],
        events: [],
      };
      const next = state.stages.slice();
      next.splice(afterIndex + 1, 0, restDay);
      const stages = renumber(next);
      return { stages, ...endDatePatch(state.startDate, stages.length) };
    }),
  insertStageOptimistic: (afterIndex, placeholder) =>
    set((state) => {
      const next = state.stages.slice();
      next.splice(afterIndex + 1, 0, placeholder);
      const stages = renumber(next);
      return { stages, ...endDatePatch(state.startDate, stages.length) };
    }),
  moveStageOptimistic: (fromIndex, toIndex) =>
    set((state) => {
      const next = state.stages.slice();
      const [moved] = next.splice(fromIndex, 1);
      if (!moved) return {};
      next.splice(toIndex, 0, moved);
      return { stages: renumber(next) };
    }),
  selectAccommodationOptimistic: (stageIndex, accIndex, nextStageIndex) =>
    set((state) => {
      const stage = state.stages[stageIndex];
      const acc = stage?.accommodations[accIndex];
      if (!stage || !acc) return {};
      const endPoint = { lat: acc.lat, lon: acc.lon, ele: 0 };
      const stages = state.stages.map((s, i) => {
        if (i === stageIndex) {
          return {
            ...s,
            accommodations: [acc],
            selectedAccommodation: acc,
            endPoint,
          };
        }
        if (i === nextStageIndex) {
          return { ...s, startPoint: endPoint };
        }
        return s;
      });
      return { stages };
    }),
  // Only clears the selection. Unlike selectAccommodationOptimistic we do NOT
  // revert endPoint / the next startPoint: the pre-selection route endpoint is
  // not retained here, so the stage stays pinned to the former accommodation
  // until the SSE recompute streams the authoritative geometry. A transient,
  // self-healing inconsistency by design (rerouting → requiresRouting: true).
  deselectAccommodationOptimistic: (stageIndex) =>
    set((state) => ({
      stages: state.stages.map((s, i) =>
        i === stageIndex ? { ...s, selectedAccommodation: null } : s,
      ),
    })),
  armConfigDiff: () =>
    set((state) => ({
      // Only capture the snapshot for a fresh run (no baseline pending). A re-arm
      // before the pending trip_ready must NOT recapture: `stages` may already
      // have advanced (an earlier generation resolved while a later one is still
      // pending), and overwriting the shared baseline with the newer stages would
      // corrupt the still-pending generation's diff.
      diffBaseline: state.diffBaseline ?? state.stages,
      // A fresh arm starts a new generation run at 1 and clears any counters left
      // over from a commit that never streamed back (a failed commit is disarmed
      // by ConfigSheet / the SSE error path). A re-arm bumps the generation and
      // keeps the consumed count.
      diffBaselineToken: state.diffBaseline ? state.diffBaselineToken + 1 : 1,
      diffConsumedToken: state.diffBaseline ? state.diffConsumedToken : 0,
    })),
  disarmConfigDiff: () =>
    set((state) => {
      if (!state.diffBaseline) return {};
      // A failed commit is a consumed generation, not a full reset: only release
      // the shared baseline once every armed generation is accounted for, so a
      // second still-pending generation keeps its baseline (mirrors the consumed
      // accounting in applyTripReady).
      const consumed = state.diffConsumedToken + 1;
      if (consumed >= state.diffBaselineToken) {
        return { diffBaseline: null, diffBaselineToken: 0, diffConsumedToken: 0 };
      }
      return { diffConsumedToken: consumed };
    }),
  clearStageDiffs: () => set({ stageDiffs: new Set<number>() }),
  queueModification: (modification) =>
    set((state) => {
      const i = state.pendingModifications.findIndex(
        (m) =>
          m.type === modification.type &&
          m.stageIndex === modification.stageIndex,
      );
      const next = state.pendingModifications.slice();
      if (i !== -1) next[i] = modification;
      else next.push(modification);
      return { pendingModifications: next };
    }),
  cancelAllModifications: () => set({ pendingModifications: [] }),
  clearPendingModifications: () => set({ pendingModifications: [] }),
  setStatus: (patch) => set(patch),
  reset: () => {
    useTripTemporalStore.getState().clear();
    set({
      tripId: null,
      title: null,
      sourceUrl: null,
      stages: [],
      isLocked: false,
      outOfZone: false,
      computing: false,
      geometryLoaded: false,
      pendingModifications: [],
      stageDiffs: new Set<number>(),
      diffBaseline: null,
      diffBaselineToken: 0,
      diffConsumedToken: 0,
      loading: true,
      error: null,
      ...DEFAULT_CONFIG,
    });
  },
}));

/**
 * The slice tracked by undo/redo: the roadbook stages plus the editable
 * dates/pacing config. Mirrors the web {@link getUndoableSlice} — deep-cloned
 * via JSON so no live store reference leaks into the history stack.
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

export function getUndoableSlice(state: UndoableSlice): UndoableSlice {
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
 * Companion temporal store providing undo/redo for the roadbook (#1178, parity
 * with the web). Undoable mutations push a pre-edit {@link UndoableSlice} via
 * `_push()` before applying; `undo()` restores it through `setStages` /
 * `setConfig` (neither re-pushes), reverting the local optimistic state without
 * re-hitting the API — same semantics as the web temporal store.
 */
export const useTripTemporalStore = createTemporalStore(
  () => getUndoableSlice(useTripStore.getState()),
  (snapshot) => {
    const s = snapshot as UndoableSlice;
    const store = useTripStore.getState();
    store.setStages(s.stages);
    store.setConfig({
      startDate: s.startDate,
      endDate: s.endDate,
      fatigueFactor: s.fatigueFactor,
      elevationPenalty: s.elevationPenalty,
      maxDistancePerDay: s.maxDistancePerDay,
      averageSpeed: s.averageSpeed,
    });
  },
);
