import { create } from 'zustand';
import type { StageData } from '@btp/core';
import { DEFAULT_ACCOMMODATION_RADIUS_KM } from '@btp/core/constants';
import {
  reconcileStageUpdate,
  reconcileTripReady,
} from '@btp/core/reconciliation';
import type { TripDetail } from '../api/trips';

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
    geometry: (s.geometry as StageData['geometry']) ?? [],
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
    pois: (s.pois as StageData['pois']) ?? [],
    accommodations: (s.accommodations as StageData['accommodations']) ?? [],
    selectedAccommodation:
      (s.selectedAccommodation as StageData['selectedAccommodation']) ?? null,
    accommodationSearchRadiusKm: DEFAULT_ACCOMMODATION_RADIUS_KM,
    isRestDay: s.isRestDay ?? false,
    supplyTimeline: [],
    events: [],
  };
}

interface TripState {
  tripId: string | null;
  title: string | null;
  stages: StageData[];
  // Trip started (startDate <= today): the backend rejects edits with 423, so the
  // UI disables them. Read from the /detail payload on hydrate.
  isLocked: boolean;
  loading: boolean;
  error: string | null;
  // Initial load from a /trips/{id}/detail payload.
  hydrate: (tripId: string, detail: TripDetail) => void;
  // Mode 1 terminal event: reconcile the whole trip via the shared core reducer.
  applyTripReady: (stages: StageData[]) => void;
  // Mode 2 per-stage event: reconcile a single slice via the shared core reducer.
  applyStageUpdate: (index: number, stage: StageData) => void;
  // Replace the whole stage list (optimistic rollback restores a snapshot).
  setStages: (stages: StageData[]) => void;
  // Optimistic delete: drop a stage and renumber the days, mirroring the web
  // store. The authoritative state arrives via the SSE reconciliation; on API
  // failure the caller restores the pre-delete snapshot via setStages.
  deleteStageOptimistic: (index: number) => void;
  setStatus: (patch: { loading?: boolean; error?: string | null }) => void;
  reset: () => void;
}

// Thin RN store: it holds StageData and delegates every reconciliation to the
// pure reducers in @btp/core, so the web and mobile stores share one source of
// truth and this file carries no reconciliation logic of its own (#1014).
export const useTripStore = create<TripState>((set, get) => ({
  tripId: null,
  title: null,
  stages: [],
  isLocked: false,
  loading: true,
  error: null,
  hydrate: (tripId, detail) =>
    set({
      tripId,
      title: detail.title ?? null,
      stages: (detail.stages ?? []).map(stageDataFromDetail),
      isLocked: detail.isLocked ?? false,
      loading: false,
      error: null,
    }),
  applyTripReady: (stages) =>
    set({ stages: reconcileTripReady(get().stages, stages) }),
  applyStageUpdate: (index, stage) =>
    set({ stages: reconcileStageUpdate(get().stages, index, stage).stages }),
  setStages: (stages) => set({ stages }),
  deleteStageOptimistic: (index) =>
    set((state) => ({
      stages: state.stages
        .filter((_, i) => i !== index)
        .map((stage, i) => ({ ...stage, dayNumber: i + 1 })),
    })),
  setStatus: (patch) => set(patch),
  reset: () =>
    set({
      tripId: null,
      title: null,
      stages: [],
      isLocked: false,
      loading: true,
      error: null,
    }),
}));
