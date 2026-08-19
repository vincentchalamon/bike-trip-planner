import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import { DEFAULT_ACCOMMODATION_RADIUS_KM } from '@btp/core/constants';
import {
  addPoiWaypoint,
  analyzeTrip,
  applyBatchRecompute,
  createStage,
  deleteTrip,
  duplicateTrip,
  insertRestDay,
  moveStage,
  scanAccommodations,
  setStageAccommodation,
  updateStageDistance,
  updateTripConfig,
  type Coordinate,
  type MutationResult,
  type TripConfigPatch,
} from '../api/trips';
import {
  evaluateGate,
  normalizeStatus,
  type GateState,
  type MutationFailure,
} from './gating';
import { useOfflineStore } from './offline-store';
import type { Modification, TripConfig } from './trip-store';

// The store slice + actions the mutation runners drive. `useTripStore.getState()`
// satisfies it structurally, so a runner takes a live snapshot (the actions are
// stable, and the pre-edit `stages` snapshot is captured up front for rollback).
export interface MutationContext extends TripConfig {
  isLocked: boolean;
  outOfZone: boolean;
  title: string | null;
  stages: StageData[];
  pendingModifications: Modification[];
  setStages: (stages: StageData[]) => void;
  setConfig: (patch: Partial<TripConfig>) => void;
  setTitle: (title: string) => void;
  insertRestDayOptimistic: (afterIndex: number) => void;
  insertStageOptimistic: (afterIndex: number, placeholder: StageData) => void;
  moveStageOptimistic: (fromIndex: number, toIndex: number) => void;
  selectAccommodationOptimistic: (
    stageIndex: number,
    accIndex: number,
    nextStageIndex: number | null,
  ) => void;
  deselectAccommodationOptimistic: (stageIndex: number) => void;
  deleteStageOptimistic: (index: number) => void;
  clearPendingModifications: () => void;
}

/** Report the outcome of a mutation: null on success, a reason on failure. */
export type OnFailure = (reason: MutationFailure) => void;

// Build the gate state from the store + the shared offline flag (offline lives
// in its own store so it is readable without a trip loaded).
function gateOf(ctx: Pick<MutationContext, 'isLocked' | 'outOfZone'>): GateState {
  return {
    isLocked: ctx.isLocked,
    outOfZone: ctx.outOfZone,
    isOnline: useOfflineStore.getState().isOnline,
  };
}

/**
 * Shared mutation shell: pre-flight gate → optional optimistic apply → API call
 * → normalized failure + rollback. Every runner funnels through here so gating,
 * error classification and the rollback discipline stay identical across screens
 * (#1031). Returns true only when the backend accepted the mutation.
 */
export async function run(
  ctx: MutationContext,
  opts: {
    requiresRouting: boolean;
    call: () => Promise<MutationResult>;
    optimistic?: () => void;
    rollback?: () => void;
    // Fired (after rollback, before onFailure) when the backend answers 409. The
    // accommodation runners use it to trigger a re-scan of the stale candidate
    // list rather than leaving the user stuck on it.
    onConflict?: () => void;
  },
  onFailure: OnFailure,
): Promise<boolean> {
  const blocked = evaluateGate(gateOf(ctx), opts.requiresRouting);
  if (blocked) {
    onFailure(blocked);
    return false;
  }
  opts.optimistic?.();
  try {
    const { ok, status } = await opts.call();
    if (!ok) {
      opts.rollback?.();
      const reason = normalizeStatus(status);
      if (reason === 'conflict') opts.onConflict?.();
      onFailure(reason);
      return false;
    }
    return true;
  } catch {
    opts.rollback?.();
    onFailure('network');
    return false;
  }
}

// Assemble a full JSON Merge Patch body from the current config + overrides. The
// backend PATCH schema requires the whole pacing block, so send it every time.
function configPatch(
  ctx: MutationContext,
  overrides: Partial<TripConfigPatch>,
): TripConfigPatch {
  return {
    fatigueFactor: ctx.fatigueFactor,
    elevationPenalty: ctx.elevationPenalty,
    maxDistancePerDay: ctx.maxDistancePerDay,
    averageSpeed: ctx.averageSpeed,
    ebikeMode: ctx.ebikeMode,
    departureHour: ctx.departureHour,
    enabledAccommodationTypes: ctx.enabledAccommodationTypes,
    startDate: ctx.startDate,
    endDate: ctx.endDate,
    ...overrides,
  };
}

// --- Trip-level config (no Valhalla rerouting → requiresRouting: false) -------

export function runUpdateDates(
  tripId: string,
  startDate: string | null,
  endDate: string | null,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const snapshot = { startDate: ctx.startDate, endDate: ctx.endDate };
  return run(
    ctx,
    {
      requiresRouting: false,
      optimistic: () => ctx.setConfig({ startDate, endDate }),
      rollback: () => ctx.setConfig(snapshot),
      call: () =>
        updateTripConfig(tripId, configPatch(ctx, { startDate, endDate })),
    },
    onFailure,
  );
}

export function runUpdatePacing(
  tripId: string,
  pacing: Pick<
    TripConfig,
    | 'fatigueFactor'
    | 'elevationPenalty'
    | 'maxDistancePerDay'
    | 'averageSpeed'
    | 'ebikeMode'
    | 'departureHour'
  >,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const snapshot: Partial<TripConfig> = {
    fatigueFactor: ctx.fatigueFactor,
    elevationPenalty: ctx.elevationPenalty,
    maxDistancePerDay: ctx.maxDistancePerDay,
    averageSpeed: ctx.averageSpeed,
    ebikeMode: ctx.ebikeMode,
    departureHour: ctx.departureHour,
  };
  return run(
    ctx,
    {
      requiresRouting: false,
      optimistic: () => ctx.setConfig(pacing),
      rollback: () => ctx.setConfig(snapshot),
      call: () => updateTripConfig(tripId, configPatch(ctx, pacing)),
    },
    onFailure,
  );
}

export function runUpdateAccommodationTypes(
  tripId: string,
  types: string[],
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const snapshot = ctx.enabledAccommodationTypes;
  return run(
    ctx,
    {
      requiresRouting: false,
      optimistic: () => ctx.setConfig({ enabledAccommodationTypes: types }),
      rollback: () => ctx.setConfig({ enabledAccommodationTypes: snapshot }),
      call: () =>
        updateTripConfig(
          tripId,
          configPatch(ctx, { enabledAccommodationTypes: types }),
        ),
    },
    onFailure,
  );
}

export function runUpdateTitle(
  tripId: string,
  title: string,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const snapshot = ctx.title ?? '';
  return run(
    ctx,
    {
      requiresRouting: false,
      optimistic: () => ctx.setTitle(title),
      rollback: () => ctx.setTitle(snapshot),
      call: () => updateTripConfig(tripId, configPatch(ctx, { title })),
    },
    onFailure,
  );
}

// --- Stage structural edits ---------------------------------------------------
// (runDeleteStage lives in delete-stage.ts, its own thin wrapper from #1015; it
// composes the same `run` shell so gating/rollback stay identical.)

export function runInsertRestDay(
  tripId: string,
  afterIndex: number,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const snapshot = ctx.stages;
  return run(
    ctx,
    {
      // Inserting a rest day keeps the next startPoint identical: no reroute.
      requiresRouting: false,
      optimistic: () => ctx.insertRestDayOptimistic(afterIndex),
      rollback: () => ctx.setStages(snapshot),
      call: () => insertRestDay(tripId, afterIndex),
    },
    onFailure,
  );
}

export function runAddStage(
  tripId: string,
  afterIndex: number,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const prev = ctx.stages[afterIndex];
  const next = ctx.stages[afterIndex + 1];
  const startPoint = prev?.endPoint ?? prev?.startPoint;
  const endPoint = next?.startPoint ?? prev?.endPoint;
  if (!startPoint || !endPoint) {
    onFailure('error');
    return Promise.resolve(false);
  }
  const placeholder: StageData = {
    dayNumber: afterIndex + 2,
    distance: 0,
    elevation: 0,
    elevationLoss: 0,
    startPoint: { lat: startPoint.lat, lon: startPoint.lon, ele: startPoint.ele },
    endPoint: { lat: endPoint.lat, lon: endPoint.lon, ele: endPoint.ele },
    geometry: [],
    label: null,
    startLabel: prev?.endLabel ?? null,
    endLabel: next?.startLabel ?? null,
    weather: null,
    alerts: [],
    resupply: EMPTY_RESUPPLY,
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: DEFAULT_ACCOMMODATION_RADIUS_KM,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
  };
  const snapshot = ctx.stages;
  const start: Coordinate = { ...startPoint };
  const end: Coordinate = { ...endPoint };
  return run(
    ctx,
    {
      // A manual stage is routed via Valhalla → blocked out of zone.
      requiresRouting: true,
      optimistic: () => ctx.insertStageOptimistic(afterIndex, placeholder),
      rollback: () => ctx.setStages(snapshot),
      call: () =>
        createStage(tripId, {
          position: afterIndex + 1,
          startPoint: start,
          endPoint: end,
        }),
    },
    onFailure,
  );
}

export function runUpdateStageDistance(
  tripId: string,
  index: number,
  distance: number,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  // No optimistic geometry change: the backend re-splits and streams the
  // authoritative stages over SSE. Re-splitting reroutes → routing gate.
  return run(
    ctx,
    {
      requiresRouting: true,
      call: () => updateStageDistance(tripId, index, distance),
    },
    onFailure,
  );
}

export function runMoveStage(
  tripId: string,
  fromIndex: number,
  toIndex: number,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const snapshot = ctx.stages;
  return run(
    ctx,
    {
      requiresRouting: true,
      optimistic: () => ctx.moveStageOptimistic(fromIndex, toIndex),
      rollback: () => ctx.setStages(snapshot),
      call: () => moveStage(tripId, fromIndex, toIndex),
    },
    onFailure,
  );
}

// --- Accommodation ------------------------------------------------------------

export function runSelectAccommodation(
  tripId: string,
  stageIndex: number,
  accIndex: number,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const acc = ctx.stages[stageIndex]?.accommodations[accIndex];
  if (!acc) {
    onFailure('error');
    return Promise.resolve(false);
  }
  const nextStageIndex =
    stageIndex + 1 < ctx.stages.length ? stageIndex + 1 : null;
  const snapshot = ctx.stages;
  return run(
    ctx,
    {
      // Selecting shifts the stage endpoint → the stage is re-routed.
      requiresRouting: true,
      optimistic: () =>
        ctx.selectAccommodationOptimistic(stageIndex, accIndex, nextStageIndex),
      rollback: () => ctx.setStages(snapshot),
      call: () => setStageAccommodation(tripId, stageIndex, acc.lat, acc.lon),
      // 409 = a concurrent scan invalidated the candidate list. Re-scan this
      // stage at the default radius so the user gets a fresh list to retry from
      // (mirrors the web handleSelectAccommodation flow). A failed re-scan is
      // surfaced rather than swallowed (no silent unhandled rejection).
      onConflict: () =>
        void scanAccommodations(
          tripId,
          DEFAULT_ACCOMMODATION_RADIUS_KM,
          stageIndex,
        ).catch(() => onFailure('network')),
    },
    onFailure,
  );
}

export function runDeselectAccommodation(
  tripId: string,
  stageIndex: number,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const snapshot = ctx.stages;
  return run(
    ctx,
    {
      requiresRouting: true,
      optimistic: () => ctx.deselectAccommodationOptimistic(stageIndex),
      rollback: () => ctx.setStages(snapshot),
      call: () => setStageAccommodation(tripId, stageIndex, null, null),
      onConflict: () =>
        void scanAccommodations(
          tripId,
          DEFAULT_ACCOMMODATION_RADIUS_KM,
          stageIndex,
        ).catch(() => onFailure('network')),
    },
    onFailure,
  );
}

export function runScanAccommodations(
  tripId: string,
  radiusKm: number,
  stageIndex: number | undefined,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  // A scan reads POIs; it does not reroute → no zone gate.
  return run(
    ctx,
    {
      requiresRouting: false,
      call: () => scanAccommodations(tripId, radiusKm, stageIndex),
    },
    onFailure,
  );
}

// --- Route waypoint -----------------------------------------------------------

export function runAddPoiWaypoint(
  tripId: string,
  stageIndex: number,
  lat: number,
  lon: number,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  return run(
    ctx,
    {
      requiresRouting: true,
      call: () => addPoiWaypoint(tripId, stageIndex, lat, lon),
    },
    onFailure,
  );
}

// --- Batch recompute + analyze ------------------------------------------------

export function runApplyBatch(
  tripId: string,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const mods = ctx.pendingModifications;
  if (mods.length === 0) return Promise.resolve(false);
  // A distance or accommodation edit reroutes; a pure dates/pacing batch does not.
  const requiresRouting = mods.some(
    (m) => m.type === 'distance' || m.type === 'accommodation',
  );
  return run(
    ctx,
    {
      requiresRouting,
      call: () =>
        applyBatchRecompute(
          tripId,
          mods.map((m) => ({
            stageIndex: m.stageIndex,
            type: m.type,
            label: m.label,
          })),
        ),
    },
    onFailure,
  ).then((ok) => {
    if (ok) ctx.clearPendingModifications();
    return ok;
  });
}

export function runAnalyze(
  tripId: string,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  // Re-enrichment (POIs, weather, terrain): no Valhalla reroute.
  return run(
    ctx,
    { requiresRouting: false, call: () => analyzeTrip(tripId) },
    onFailure,
  );
}

// --- Trip lifecycle -----------------------------------------------------------

/**
 * Duplicate the trip. Allowed on a started (locked) or out-of-zone trip — it
 * clones rather than edits — but not offline. Returns the new id, or null with
 * the failure reported.
 */
export async function runDuplicateTrip(
  tripId: string,
  _ctx: MutationContext,
  onFailure: OnFailure,
): Promise<string | null> {
  if (!useOfflineStore.getState().isOnline) {
    onFailure('offline');
    return null;
  }
  try {
    const id = await duplicateTrip(tripId);
    if (!id) {
      onFailure('error');
      return null;
    }
    return id;
  } catch {
    onFailure('network');
    return null;
  }
}

/** Delete the whole trip. Blocked only while offline (a lock does not apply). */
export function runDeleteTrip(
  tripId: string,
  ctx: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const online = useOfflineStore.getState().isOnline;
  if (!online) {
    onFailure('offline');
    return Promise.resolve(false);
  }
  return deleteTrip(tripId)
    .then(({ ok, status }) => {
      if (!ok) {
        onFailure(normalizeStatus(status));
        return false;
      }
      return true;
    })
    .catch(() => {
      onFailure('network');
      return false;
    });
}
