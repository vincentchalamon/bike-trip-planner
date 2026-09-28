"use client";

import { useEffect, useRef } from "react";
import { MercureClient } from "@/lib/mercure/client";
import type { MercureEvent } from "@btp/core/mercure";
import { useTripStore } from "@/store/trip-store";
import { useAuthStore } from "@/store/auth-store";
import { setTripVersion } from "@/lib/api/client";
import {
  enrichedPayloadToStageData,
  reduceMercureEvent,
} from "@btp/core/reconciliation";
import { resolveMercureHubUrl } from "@/lib/mercure/hub-url";
import { applySideEffect } from "@/lib/mercure/side-effects";

/**
 * Reduce one server-pushed event into the store, then let the interface react.
 *
 * Two steps, deliberately separate (#1330). The STATE comes from the shared reducer in
 * `@btp/core/reconciliation`, the same one mobile runs, so there is one source of truth for
 * what an event means (ADR-055) — `#1030` pins this hook's output against it for every event.
 * What a browser then does about it — toasts, spinners, geocoding, the transient highlight —
 * lives in `lib/mercure/side-effects.ts` and cannot reach back into the reduction.
 *
 * `stage_updated` keeps its dedicated action for the endDate bookkeeping core leaves to the
 * store, and its recompute marker is settled right after.
 */
function reduceAndReact(
  event: MercureEvent,
  // Aborted when the subscription tears down (unmount / trip-switch), so a late reverse-geocode
  // reply cannot overwrite another trip's labels (#787). Scoped per subscription rather than
  // module-global.
  signal: AbortSignal,
  // Per-subscription stage-diff timers, keyed by stage identifier. Owned by useMercure and
  // cleared on teardown so a timer from trip A never fires against trip B.
  timers: Map<string, ReturnType<typeof setTimeout>>,
): void {
  const store = useTripStore.getState();
  // Snapshot the pre-mutation stages: the stage_updated diff needs both sides.
  const previousStages = store.stages;

  if (event.type === "stage_updated") {
    store.applyStageUpdate(
      event.data.stageId,
      event.data.position,
      enrichedPayloadToStageData(event.data.stage),
    );
    store.finishStageRecomputation(event.data.stageId);
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

  applySideEffect(event, {
    previousStages,
    currentStages: useTripStore.getState().stages,
    signal,
    timers,
  });
}

/**
 * Subscribes to Mercure SSE events for a given trip.
 *
 * Opens a persistent SSE connection to the Mercure hub on mount, routing every incoming event
 * through {@link reduceAndReact}. The connection is torn down on unmount or when `tripId`
 * changes.
 *
 * In E2E tests, the real Mercure connection is aborted via `page.route()` and events are
 * injected through `CustomEvent('__test_mercure_event')` instead.
 *
 * @param tripId - The trip identifier to subscribe to, or `null` to skip subscription
 */
export function useMercure(tripId: string | null): void {
  const clientRef = useRef<MercureClient | null>(null);

  useEffect(() => {
    if (!tripId) return;

    // One AbortController + one diff-timer map per subscription (per tripId), so a late geocode
    // reply or a pending diff timer from this trip can never land on the next one after a fast
    // switch.
    const controller = new AbortController();
    const timers = new Map<string, ReturnType<typeof setTimeout>>();

    // Re-authenticate the Mercure cookie when it expires on a long-open tab: the client calls
    // /trips/{id}/detail with this Bearer to have the backend re-pin the subscriber cookie.
    // Reuses the in-memory JWT (refreshed if needed).
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

    client.onEvent((envelope) => {
      // Record the version the envelope carries before reducing: a regeneration performed by a
      // worker moves it with no HTTP response to carry a fresh ETag, and the next edit pins
      // whatever is recorded here.
      if (envelope.version !== undefined)
        setTripVersion(tripId, envelope.version);
      reduceAndReact(envelope, controller.signal, timers);
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
