"use client";

import { useEffect, useRef } from "react";
import { MercureClient } from "@/lib/mercure/client";
import type { MercureEvent } from "@btp/core/mercure";
import { useTripStore } from "@/store/trip-store";
import { useAuthStore } from "@/store/auth-store";
import { useUiStore } from "@/store/ui-store";
import { reverseGeocode } from "@/lib/geocode/client";
import { toast } from "@/components/ui/sonner";
import {
  enrichedPayloadToStageData,
  reduceMercureEvent,
} from "@btp/core/reconciliation";
import type { StageData } from "@btp/core";

/**
 * The Mercure hub the browser subscribes to. An explicit
 * `NEXT_PUBLIC_MERCURE_URL` wins; otherwise the hub is taken from the CURRENT
 * origin, so the app works unchanged on https://localhost, in prod (same
 * origin), and behind a tunnel (ngrok) — without baking a URL into the bundle.
 * The localhost fallback only applies during SSR, where no EventSource is opened.
 */
function resolveMercureHubUrl(): string {
  if (process.env.NEXT_PUBLIC_MERCURE_URL) {
    return process.env.NEXT_PUBLIC_MERCURE_URL;
  }
  if (typeof window !== "undefined") {
    return `${window.location.origin}/.well-known/mercure`;
  }
  return "https://localhost/.well-known/mercure";
}

/**
 * Dispatches a Mercure SSE event to the appropriate Zustand store action.
 *
 * Acts as the central event router for all server-pushed computation results.
 * Each event type maps to one or more store mutations in {@link useTripStore}
 * or {@link useUiStore}. For `stages_computed` events, performs smart merging:
 * partial updates only reset derived data (weather, POIs, labels) for affected
 * stages, while full replacements preserve data for stages whose endpoints
 * did not move. Stage labels are resolved asynchronously via reverse geocoding.
 *
 * Event types handled:
 * - `route_parsed` — initial route metadata (distance, elevation, source)
 * - `stages_computed` — stage geometry and pacing (partial or full, legacy)
 * - `weather_fetched` — per-stage weather forecasts
 * - `pois_scanned` — points of interest with optional alerts
 * - `accommodations_found` — accommodation options per stage
 * - `events_found` — DataTourisme dated events per stage
 * - `supply_timeline` — clustered supply markers per stage (water + food POIs)
 * - `terrain_alerts` / `calendar_alerts` / `wind_alerts` / `bike_shop_alerts` / `water_point_alerts` / `railway_station_alerts` / `health_service_alerts` / `border_crossing_alerts` / `ferry_alerts` / `ford_alerts` — alert categories
 * - `computation_step_completed` — Mode 1 progress tick (drives progress bar)
 * - `trip_ready` — Mode 1 atomic enriched payload (final analysis swap)
 * - `stage_updated` — Mode 2 per-stage update (inline modifications)
 * - `trip_complete` — final computation status, stops processing spinner (legacy)
 * - `validation_error` / `computation_error` — error toasts and recovery
 */
function dispatchEvent(
  event: MercureEvent,
  // Aborted when the subscription tears down (unmount / trip-switch), so a late
  // reverse-geocode reply cannot overwrite another trip's labels (#787). Scoped
  // per subscription rather than module-global.
  signal: AbortSignal,
  // Per-subscription stage-diff timers, keyed by stage index. Owned by useMercure
  // and cleared on teardown so a timer from trip A never fires against trip B.
  timers: Map<number, ReturnType<typeof setTimeout>>,
): void {
  const store = useTripStore.getState();
  const ui = useUiStore.getState();
  // Snapshot the pre-mutation stages for the stage_updated diff below.
  const prevStages = store.stages;

  // STATE — one source of truth via the shared core reducer (ADR-055), the same
  // path mobile now takes; #1030 pins its output against this hook for every
  // event. `stage_updated` keeps its dedicated action for the endDate bookkeeping
  // core leaves to the store, and its recompute marker is settled right after.
  if (event.type === "stage_updated") {
    store.applyStageUpdate(
      event.data.stageIndex,
      enrichedPayloadToStageData(event.data.stage),
    );
    store.finishStageRecomputation(event.data.stageIndex);
  } else {
    store.applyReconciled(
      reduceMercureEvent(
        {
          totalDistance: store.totalDistance,
          totalElevation: store.totalElevation,
          totalElevationLoss: store.totalElevationLoss,
          sourceType: store.sourceType,
          title: store.trip?.title ?? null,
          stages: store.stages,
          computationStatus: store.computationStatus,
          recomputingStages: store.recomputingStages,
        },
        event,
      ),
    );
  }

  // Post-mutation stages, for label resolution and the diff.
  const postStages = useTripStore.getState().stages;

  // UI SIDE EFFECTS — kept OUT of core (ADR-043): toasts, block/processing
  // spinners, reverse-geocoding and the transient stage-diff highlight. The
  // recompute markers and computationStatus are already settled by the reducer
  // (validation_error / computation_error / terminals clear them there).
  switch (event.type) {
    case "stages_computed": {
      const { affectedIndices } = event.data;
      if (
        affectedIndices &&
        affectedIndices.length > 0 &&
        prevStages.length > 0
      ) {
        // Partial update: only the affected stages had their labels reset.
        const affected = new Set(affectedIndices);
        const affectedStages = postStages.filter((_, i) => affected.has(i));
        if (affectedStages.length > 0) {
          resolveStageLabels(affectedStages, affectedIndices, signal);
        }
      } else {
        // Full replace: geocode every stage still missing a label.
        const needsLabels = postStages
          .map((s, i) => ({ s, i }))
          .filter(({ s }) => s.startLabel === null || s.endLabel === null);
        if (needsLabels.length > 0) {
          resolveStageLabels(
            needsLabels.map(({ s }) => s),
            needsLabels.map(({ i }) => i),
            signal,
          );
        }
      }
      break;
    }

    case "weather_fetched":
      // Weather enrichment landed — resolve its per-block spinner (ADR-043).
      ui.setBlockStatus("weather", "done");
      break;

    case "accommodations_found":
      // Settle the "Recherche d'hébergements" spinner as soon as results land: a
      // standalone scan (expand-radius / 409 re-scan) never emits a terminal
      // trip_ready/trip_complete (recette #649).
      ui.setAccommodationScanning(false);
      break;

    case "route_segment_recalculated":
      ui.setProcessing(false);
      break;

    case "trip_complete":
      // Terminal completion — settle the global overlays and the weather block
      // spinner (safety net in case weather_fetched never fired).
      ui.setBlockStatus("weather", "done");
      ui.setProcessing(false);
      ui.setAccommodationScanning(false);
      break;

    case "trip_ready": {
      // The terminal enrichment payload landed — settle the overlays and the
      // weather block spinner, then geocode any stage still missing a label.
      ui.setBlockStatus("weather", "done");
      ui.setProcessing(false);
      ui.setAccommodationScanning(false);
      const needsLabels = postStages
        .map((s, i) => ({ s, i }))
        .filter(({ s }) => s.startLabel === null || s.endLabel === null);
      if (needsLabels.length > 0) {
        resolveStageLabels(
          needsLabels.map(({ s }) => s),
          needsLabels.map(({ i }) => i),
          signal,
        );
      }
      break;
    }

    case "stage_updated": {
      const index = event.data.stageIndex;
      const prevStage = prevStages[index];
      const nextStage = postStages[index];

      // Transient diff-highlight of the changed fields (reads pre vs post state).
      if (prevStage && nextStage) {
        const changed = computeStageDiff(prevStage, nextStage);
        if (changed.size > 0) {
          const existingTimer = timers.get(index);
          if (existingTimer !== undefined) clearTimeout(existingTimer);
          store.setStageDiff(index, changed);
          const timer = setTimeout(() => {
            useTripStore.getState().clearStageDiff(index);
            timers.delete(index);
          }, 3000);
          timers.set(index, timer);
        }
      }

      // A batch/inline recompute (Mode 2) never emits a terminal trip_complete,
      // so once the last recomputing stage settles, clear the global processing
      // overlay here — otherwise it spins forever (recette #649). Tied to
      // recomputingStages so the initial full analysis keeps relying on trip_ready.
      if (useTripStore.getState().recomputingStages.size === 0) {
        ui.setProcessing(false);
      }

      // Labels may have been wiped if endpoints moved — refresh if needed.
      if (
        nextStage &&
        (nextStage.startLabel === null || nextStage.endLabel === null)
      ) {
        resolveStageLabels([nextStage], [index], signal);
      }
      break;
    }

    case "validation_error":
      toast.error(event.data.message);
      ui.setProcessing(false);
      ui.setAccommodationScanning(false);
      break;

    case "computation_error": {
      toast.error(`Computation failed: ${event.data.message}`);
      // Map the failed computation onto its per-block spinner so the matching
      // block surfaces an error + retry affordance (ADR-043). Weather/wind →
      // weather. Other computations have no dedicated block.
      const computation = event.data.computation;
      if (computation === "weather" || computation === "wind") {
        ui.setBlockStatus("weather", "failed");
      }
      if (!event.data.retryable) {
        ui.setProcessing(false);
        ui.setAccommodationScanning(false);
      }
      break;
    }
  }
}

/**
 * Compares a previous and incoming stage snapshot and returns the set of
 * logical field names that have changed. Used to populate `stageDiffs` in
 * the store so that `DiffHighlight` can transiently highlight each changed
 * piece of data.
 *
 * Compared fields: `distance`, `alerts_added`.
 */
function computeStageDiff(prev: StageData, next: StageData): Set<string> {
  const changed = new Set<string>();

  if (prev.distance !== next.distance) changed.add("distance");

  // Alert changes: detect newly added alerts only
  const prevMessages = new Set(
    prev.alerts.map((a) => `${a.type}:${a.message}`),
  );
  const nextMessages = new Set(
    next.alerts.map((a) => `${a.type}:${a.message}`),
  );
  const hasNewAlerts = [...nextMessages].some((m) => !prevMessages.has(m));
  if (hasNewAlerts) changed.add("alerts_added");

  return changed;
}

export async function resolveStageLabels(
  stages: {
    startPoint: { lat: number; lon: number };
    endPoint: { lat: number; lon: number };
  }[],
  indices?: number[],
  signal?: AbortSignal,
): Promise<void> {
  const store = useTripStore.getState();
  const promises = stages.flatMap((stage, i) => {
    const storeIndex = indices ? (indices[i] ?? i) : i;
    return [
      reverseGeocode(stage.startPoint.lat, stage.startPoint.lon, signal).then(
        (result) => {
          // Drop late responses after the caller aborted (e.g. unmount /
          // trip-switch) so a stale Nominatim reply cannot overwrite the labels
          // of a different trip (#787).
          if (result && !signal?.aborted)
            store.updateStageLabel(storeIndex, "startLabel", result.name);
        },
      ),
      reverseGeocode(stage.endPoint.lat, stage.endPoint.lon, signal).then(
        (result) => {
          if (result && !signal?.aborted)
            store.updateStageLabel(storeIndex, "endLabel", result.name);
        },
      ),
    ];
  });

  await Promise.all(promises);
}

/**
 * Subscribes to Mercure SSE events for a given trip.
 *
 * Opens a persistent SSE connection to the Mercure hub on mount, routing all
 * incoming events through {@link dispatchEvent}. The connection is torn down
 * on unmount or when the `tripId` changes.
 *
 * In E2E tests, the real Mercure connection is aborted via `page.route()` and
 * events are injected through `CustomEvent('__test_mercure_event')` instead.
 *
 * @param tripId - The trip identifier to subscribe to, or `null` to skip subscription
 */
export function useMercure(tripId: string | null): void {
  const clientRef = useRef<MercureClient | null>(null);

  useEffect(() => {
    if (!tripId) return;

    // One AbortController + one diff-timer map per subscription (per tripId), so a
    // late geocode reply or a pending diff timer from this trip can never land on
    // the next one after a fast switch.
    const controller = new AbortController();
    const timers = new Map<number, ReturnType<typeof setTimeout>>();

    // Re-authenticate the Mercure cookie when it expires on a long-open tab: the
    // client calls /trips/{id}/detail with this Bearer to have the backend re-pin
    // the subscriber cookie. Reuses the in-memory JWT (refreshed if needed).
    const client = new MercureClient(
      resolveMercureHubUrl(),
      `/trips/${tripId}`,
      async () => {
        await useAuthStore.getState().ensureResolved();
        const { accessToken } = useAuthStore.getState();
        return accessToken ? `Bearer ${accessToken}` : null;
      },
    );
    clientRef.current = client;

    client.onEvent((event) => {
      dispatchEvent(event, controller.signal, timers);
    });

    return () => {
      controller.abort();
      for (const timer of timers.values()) clearTimeout(timer);
      timers.clear();
      client.close();
      clientRef.current = null;
    };
  }, [tripId]);
}
