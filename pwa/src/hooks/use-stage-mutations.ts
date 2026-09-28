"use client";

import { useTranslations } from "next-intl";
import { toast } from "@/components/ui/sonner";
import {
  useTripStore,
  useTripTemporalStore,
  getUndoableSlice,
} from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import {
  apiClient,
  preconditionHeader,
  addPoiWaypointToRoute,
} from "@/lib/api/client";
import { DEFAULT_ACCOMMODATION_RADIUS_KM } from "@btp/core/constants";
import { EMPTY_RESUPPLY } from "@btp/core";
import type { StageData } from "@btp/core";
import {
  armRecomputeSafetyNet,
  useReportApiError,
} from "@/hooks/trip-mutation-support";

/** Structural edits of the stage list: delete, insert, re-split, re-route. */
export function useStageMutations() {
  const t = useTranslations();
  const reportApiError = useReportApiError();
  const setProcessing = useUiStore((s) => s.setProcessing);
  const setAccommodationScanning = useUiStore(
    (s) => s.setAccommodationScanning,
  );

  async function handleDeleteStage(index: number) {
    const {
      trip,
      stages: currentStages,
      deleteStage,
    } = useTripStore.getState();
    const tripId = trip?.id;
    if (!tripId) return;

    const target = currentStages[index];
    if (!target) return;
    const stageId = target.id;
    const isRestDay = target.isRestDay ?? false;
    const edit = deleteStage(index);

    try {
      const { error, response } = await apiClient.DELETE(
        "/trips/{tripId}/stages/{stageId}",
        {
          params: {
            path: { tripId, stageId },
            header: preconditionHeader(tripId),
          },
        },
      );
      if (error) {
        reportApiError(response.status, error);
        useTripStore.getState().rollbackStructuralEdit(edit);
      } else {
        setProcessing(true);
        if (!isRestDay) setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedDeleteStage"));
      useTripStore.getState().rollbackStructuralEdit(edit);
    }
  }

  async function handleInsertRestDay(afterIndex: number) {
    const {
      trip,
      stages: currentStages,
      insertRestDay,
    } = useTripStore.getState();
    const tripId = trip?.id;
    if (!tripId) return;

    const stageId = currentStages[afterIndex]?.id;
    if (!stageId) return;
    const edit = insertRestDay(afterIndex);

    try {
      const { error, response } = await apiClient.POST(
        "/trips/{tripId}/stages/{stageId}/rest-day",
        {
          params: {
            path: { tripId, stageId },
            header: preconditionHeader(tripId),
          },
          parseAs: "json",
        },
      );
      if (!response.ok) {
        reportApiError(response.status, error);
        useTripStore.getState().rollbackStructuralEdit(edit);
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error(t("errors.failedInsertRestDay"));
      useTripStore.getState().rollbackStructuralEdit(edit);
    }
  }

  async function handleAddStage(afterIndex: number) {
    const {
      trip,
      stages: currentStages,
      insertStagePlaceholder,
    } = useTripStore.getState();
    const tripId = trip?.id;
    if (!tripId) return;

    const prevStage = currentStages[afterIndex];
    const nextStage = currentStages[afterIndex + 1];
    const startPoint = prevStage?.endPoint ?? prevStage?.startPoint;
    const endPoint = nextStage?.startPoint ?? prevStage?.endPoint;

    if (!startPoint || !endPoint) {
      toast.error(t("errors.failedAddStage"));
      return;
    }

    const placeholder: StageData = {
      // Provisional identity: replaced by the server's when stages_computed or
      // trip_ready lands. Distinct so the reconciler treats it as its own stage.
      id: `pending-${crypto.randomUUID()}`,
      dayNumber: afterIndex + 2,
      distance: 0,
      elevation: 0,
      elevationLoss: 0,
      startPoint: {
        lat: startPoint.lat,
        lon: startPoint.lon,
        ele: startPoint.ele ?? 0,
      },
      endPoint: {
        lat: endPoint.lat,
        lon: endPoint.lon,
        ele: endPoint.ele ?? 0,
      },
      geometry: [],
      label: null,
      startLabel: prevStage?.endLabel ?? null,
      endLabel: nextStage?.startLabel ?? null,
      weather: null,
      alerts: [],
      resupply: EMPTY_RESUPPLY,
      accommodations: [],
      accommodationSearchRadiusKm: DEFAULT_ACCOMMODATION_RADIUS_KM,
      supplyTimeline: [],
      events: [],
      isRestDay: false,
    };
    // insertStagePlaceholder pushes an undo snapshot internally before mutating.
    const edit = insertStagePlaceholder(afterIndex, placeholder);

    try {
      const { error, response } = await apiClient.POST(
        "/trips/{tripId}/stages",
        {
          params: { path: { tripId }, header: preconditionHeader(tripId) },
          body: { position: afterIndex + 1, startPoint, endPoint },
        },
      );
      if (error) {
        reportApiError(response.status, error);
        useTripStore.getState().rollbackStructuralEdit(edit);
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedAddStage"));
      useTripStore.getState().rollbackStructuralEdit(edit);
    }
  }

  async function handleDistanceChange(index: number, distance: number) {
    const state = useTripStore.getState();
    const tripId = state.trip?.id;
    if (!tripId) return;

    // Capture state before the mutation so we can push it on success.
    const snapshot = getUndoableSlice(state);

    // Show the per-stage skeleton immediately — BEFORE awaiting the PATCH — so
    // the edited card and every subsequent one indicate loading and block
    // further edits while the backend re-splits. Otherwise the card keeps
    // showing the old distance for the whole request round-trip, only updating
    // when the `stage_updated` events land (recette: "la distance reste
    // identique un moment"). The backend re-splits from `index` onward
    // (StageUpdateProcessor → RecalculateStages over range(index, count-1)), so
    // the shimmer covers the same range (#840).
    const stageId = state.stages[index]?.id;
    if (!stageId) return;
    setProcessing(true);
    setAccommodationScanning(true);
    state.startStageRecomputation(
      state.stages.slice(index).map((stage) => stage.id),
    );
    armRecomputeSafetyNet();

    try {
      const { error, response } = await apiClient.PATCH(
        "/trips/{tripId}/stages/{stageId}",
        {
          params: {
            path: { tripId, stageId },
            header: preconditionHeader(tripId),
          },
          headers: { "Content-Type": "application/merge-patch+json" },
          body: { distance },
        },
      );
      if (error) {
        // Roll back the optimistic loading state so the cards become editable
        // again instead of shimmering forever on a rejected edit.
        useTripStore.getState().clearRecomputingStages();
        setProcessing(false);
        setAccommodationScanning(false);
        reportApiError(response.status, error);
      } else {
        // Push snapshot only after a successful PATCH to avoid phantom undo entries
        useTripTemporalStore.getState()._push(snapshot);
      }
    } catch {
      useTripStore.getState().clearRecomputingStages();
      setProcessing(false);
      setAccommodationScanning(false);
      toast.error(t("errors.failedUpdateLocation"));
    }
  }

  async function handleAddPoiWaypoint(
    stageIndex: number,
    poiLat: number,
    poiLon: number,
  ) {
    const { trip, outOfZone, stages } = useTripStore.getState();
    const tripId = trip?.id;
    if (!tripId) return;

    // Inserting a POI waypoint re-routes the stage via Valhalla, which has no
    // tiles outside the provisioned coverage area — block it for out-of-zone trips.
    if (outOfZone) {
      toast.error(t("outOfZone.editDisabled"));

      return;
    }

    try {
      const stageId = stages[stageIndex]?.id;
      if (!stageId) return;

      const ok = await addPoiWaypointToRoute(tripId, stageId, poiLat, poiLon);
      if (ok) {
        setProcessing(true);
      } else {
        toast.error(t("errors.unexpectedError"));
      }
    } catch {
      toast.error(t("errors.unexpectedError"));
    }
  }

  return {
    handleDeleteStage,
    handleInsertRestDay,
    handleAddStage,
    handleDistanceChange,
    handleAddPoiWaypoint,
  };
}
