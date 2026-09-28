"use client";

import { useTranslations } from "next-intl";
import { toast } from "@/components/ui/sonner";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import {
  apiClient,
  preconditionHeader,
  scanAccommodations,
  addManualAccommodation,
} from "@/lib/api/client";
import { trackEvent } from "@/lib/plausible";
import {
  MAX_ACCOMMODATION_RADIUS_KM,
  ACCOMMODATION_RADIUS_STEP_KM,
  DEFAULT_ACCOMMODATION_RADIUS_KM,
} from "@btp/core/constants";
import type { ManualAccommodationInput } from "@/components/manual-accommodation-form";
import { useReportApiError } from "@/hooks/trip-mutation-support";

/** A stage's accommodation: scan radius, selection, hors-app entries. */
export function useAccommodationMutations() {
  const t = useTranslations();
  const reportApiError = useReportApiError();
  const setProcessing = useUiStore((s) => s.setProcessing);
  const setAccommodationScanning = useUiStore(
    (s) => s.setAccommodationScanning,
  );

  async function handleExpandAccommodationRadius(
    stageIndex: number,
    currentRadiusKm: number,
  ): Promise<boolean> {
    const { trip, stages } = useTripStore.getState();
    const tripId = trip?.id;
    if (!tripId) return false;

    const nextRadius = currentRadiusKm + ACCOMMODATION_RADIUS_STEP_KM;
    if (nextRadius > MAX_ACCOMMODATION_RADIUS_KM) return false;

    try {
      const stageId = stages[stageIndex]?.id;
      const ok = await scanAccommodations(tripId, nextRadius, stageId);
      if (ok) {
        setProcessing(true);
        setAccommodationScanning(true);
        return true;
      } else {
        toast.error(t("errors.unexpectedError"));
        return false;
      }
    } catch {
      toast.error(t("errors.unexpectedError"));
      return false;
    }
  }

  /**
   * Add a hors-app accommodation (title/address/price/link) to a stage. The
   * address is geocoded backend-side into the coordinates the accommodation
   * carries; it becomes the selected one and the stage is re-routed — so this is
   * blocked out of zone like every other reroute (POI waypoint, selection).
   * Returns true only when the backend accepted it (the form closes then).
   */
  async function handleAddManualAccommodation(
    stageIndex: number,
    data: ManualAccommodationInput,
  ): Promise<boolean> {
    const { trip, outOfZone, stages } = useTripStore.getState();
    const tripId = trip?.id;
    if (!tripId) return false;

    if (outOfZone) {
      toast.error(t("outOfZone.editDisabled"));
      return false;
    }

    try {
      const stageId = stages[stageIndex]?.id;
      if (!stageId) return false;

      const { ok, status } = await addManualAccommodation(
        tripId,
        stageId,
        data,
      );
      if (!ok) {
        toast.error(
          status === 422
            ? t("errors.accommodationGeocodeFailed")
            : t("errors.unexpectedError"),
        );
        return false;
      }
      setProcessing(true);
      useTripStore.getState().startAdjacentStageRecomputation(stageIndex);
      trackEvent("accommodation_selected", { type: "other" });
      return true;
    } catch {
      toast.error(t("errors.unexpectedError"));
      return false;
    }
  }

  async function handleSelectAccommodation(
    stageIndex: number,
    accIndex: number,
  ) {
    const { trip, stages: currentStages } = useTripStore.getState();
    const tripId = trip?.id;
    if (!tripId) return;

    const acc = currentStages[stageIndex]?.accommodations[accIndex];
    if (!acc) return;

    const nextStageIndex =
      stageIndex + 1 < currentStages.length ? stageIndex + 1 : null;

    const stageId = currentStages[stageIndex]?.id;
    if (!stageId) return;

    // Optimistic update
    useTripStore
      .getState()
      .selectAccommodation(stageIndex, accIndex, nextStageIndex);

    try {
      const { error, response } = await apiClient.PATCH(
        "/trips/{tripId}/stages/{stageId}/accommodation",
        {
          params: {
            path: { tripId, stageId },
            header: preconditionHeader(tripId),
          },
          headers: { "Content-Type": "application/merge-patch+json" },
          body: {
            selectedAccommodationLat: acc.lat,
            selectedAccommodationLon: acc.lon,
          },
        },
      );
      if (error) {
        // 409 Conflict: the backend accommodation list was refreshed by a concurrent
        // scan — trigger a fresh scan for this stage so the user can retry.
        if (response.status === 409) {
          useTripStore.getState().setStages([...currentStages]);
          toast.info(t("errors.accommodationStale"));
          const ok = await scanAccommodations(
            tripId,
            DEFAULT_ACCOMMODATION_RADIUS_KM,
            stageId,
          );
          if (ok) {
            setAccommodationScanning(true);
          } else {
            toast.error(t("errors.unexpectedError"));
          }
        } else {
          reportApiError(response.status, error);
          // Rollback on error: restore accommodations from store snapshot
          useTripStore.getState().setStages([...currentStages]);
        }
      } else {
        setProcessing(true);
        // The selected stage and the next one (its startPoint may have shifted
        // to the accommodation) recompute.
        useTripStore.getState().startAdjacentStageRecomputation(stageIndex);
        trackEvent("accommodation_selected", { type: acc.type });
      }
    } catch {
      toast.error(t("errors.failedSelectAccommodation"));
      useTripStore.getState().setStages([...currentStages]);
    }
  }

  async function handleDeselectAccommodation(stageIndex: number) {
    const { trip, stages: currentStages } = useTripStore.getState();
    const tripId = trip?.id;
    if (!tripId) return;

    const stageId = currentStages[stageIndex]?.id;
    if (!stageId) return;

    // Optimistic update
    useTripStore.getState().deselectAccommodation(stageIndex);

    try {
      const { error, response } = await apiClient.PATCH(
        "/trips/{tripId}/stages/{stageId}/accommodation",
        {
          params: {
            path: { tripId, stageId },
            header: preconditionHeader(tripId),
          },
          headers: { "Content-Type": "application/merge-patch+json" },
          body: {
            selectedAccommodationLat: null,
            selectedAccommodationLon: null,
          },
        },
      );
      if (error) {
        reportApiError(response.status, error);
        useTripStore.getState().setStages([...currentStages]);
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
        // The deselected stage and the next one (its startPoint reverts to
        // original after deselection) recompute.
        useTripStore.getState().startAdjacentStageRecomputation(stageIndex);
      }
    } catch {
      toast.error(t("errors.failedDeselectAccommodation"));
      useTripStore.getState().setStages([...currentStages]);
    }
  }

  return {
    handleExpandAccommodationRadius,
    handleAddManualAccommodation,
    handleSelectAccommodation,
    handleDeselectAccommodation,
  };
}
