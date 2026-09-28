"use client";

import { useTranslations } from "next-intl";
import { toast } from "@/components/ui/sonner";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { parseApiError, localizedApiErrorMessage } from "@/lib/api/client";

/**
 * Last-resort delay after which a recompute that never fully settled (a lost
 * or obsolete `stage_updated`, e.g. after the day count changed) has its
 * `processing` overlay force-lifted. Generous enough to outlast a real
 * recompute + enrichment pass so it only fires on a genuinely stuck run (#840).
 */
const RECOMPUTE_OVERLAY_TIMEOUT_MS = 30000;

// Module scope rather than a ref: the edit that arms it comes from a stage card, and that card
// is swapped for a skeleton as soon as its recompute starts — a ref cleared on its unmount
// would disarm the net the moment it is needed.
let recomputeTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * Arm a last-resort timer that lifts the `processing` overlay if the current
 * recompute never fully settles (lost/obsolete `stage_updated`, or a day-count
 * change that leaves marked stages without a matching event). The timer
 * captures the recompute token (and the trip it belongs to) at arm time and
 * no-ops if a newer edit has since bumped the token or the user switched
 * trips — so overlapping edits (or a trip switch) can't clear each other's
 * overlay (#840).
 */
export function armRecomputeSafetyNet(): void {
  disarmRecomputeSafetyNet();
  const version = useTripStore.getState().recomputeVersion;
  const armedTripId = useTripStore.getState().trip?.id ?? null;
  recomputeTimer = setTimeout(() => {
    recomputeTimer = null;
    const s = useTripStore.getState();
    if (
      s.recomputeVersion !== version ||
      s.recomputingStages.size === 0 ||
      (s.trip?.id ?? null) !== armedTripId
    ) {
      return;
    }
    s.clearRecomputingStages();
    const ui = useUiStore.getState();
    ui.setProcessing(false);
    ui.setAccommodationScanning(false);
  }, RECOMPUTE_OVERLAY_TIMEOUT_MS);
}

export function disarmRecomputeSafetyNet(): void {
  if (recomputeTimer) clearTimeout(recomputeTimer);
  recomputeTimer = null;
}

/**
 * Surfaces a failed mutation, and re-reads the trip when the server refused it as computed
 * against a version the trip has moved past.
 *
 * The edit is never replayed: the client had a view the server no longer holds, so
 * re-sending it is the one recovery that could apply it to a state it was not meant for.
 * Re-reading puts the user back in front of the current trip, with their change to redo.
 */
export function useReportApiError(): (status: number, error: unknown) => void {
  const t = useTranslations();

  return (status, error) => {
    const apiError = parseApiError(status, error);
    toast.error(localizedApiErrorMessage(apiError, t));
    if (apiError.type === "stale") useUiStore.getState().requestTripResync();
  };
}

/** Read current pacing + config state from the store without subscribing. */
export function getPacingState() {
  const s = useTripStore.getState();
  return {
    fatigueFactor: s.fatigueFactor,
    elevationPenalty: s.elevationPenalty,
    maxDistancePerDay: s.maxDistancePerDay,
    averageSpeed: s.averageSpeed,
    ebikeMode: s.ebikeMode,
    departureHour: s.departureHour,
    enabledAccommodationTypes: s.enabledAccommodationTypes,
  };
}
