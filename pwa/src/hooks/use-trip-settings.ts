"use client";

import { useRef } from "react";
import { toast } from "@/components/ui/sonner";
import { useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import { useShallow } from "zustand/react/shallow";
import {
  useTripStore,
  useTripTemporalStore,
  getUndoableSlice,
  discardUndoEntry,
} from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import {
  apiClient,
  preconditionHeader,
  isNetworkError,
  duplicateTrip,
  deleteTrip,
} from "@/lib/api/client";
import type { AccommodationType } from "@/lib/accommodation-types";
import type { StageData } from "@btp/core";
import type { StageAlert } from "@btp/core/reconciliation";
import {
  getPacingState,
  useReportApiError,
} from "@/hooks/trip-mutation-support";

/** Trip-wide settings (title, dates, pacing, filters) and whole-trip actions. */
export function useTripSettings() {
  const t = useTranslations();
  const router = useRouter();
  const reportApiError = useReportApiError();
  const trip = useTripStore((s) => s.trip);
  const tripId = trip?.id ?? null;

  const actions = useTripStore(
    useShallow((s) => ({
      updateTitle: s.updateTitle,
      updateDates: s.updateDates,
      clearTrip: s.clearTrip,
      updatePacingSettingsInternal: s.updatePacingSettingsInternal,
      setEbikeMode: s.setEbikeMode,
      setEnabledAccommodationTypes: s.setEnabledAccommodationTypes,
      updateStageAlerts: s.updateStageAlerts,
      setIsLocked: s.setIsLocked,
      setDepartureHour: s.setDepartureHour,
      startStageRecomputation: s.startStageRecomputation,
      claimSettings: s.claimSettings,
      settleSettings: s.settleSettings,
      revertSettings: s.revertSettings,
    })),
  );

  const setProcessing = useUiStore((s) => s.setProcessing);
  const setAccommodationScanning = useUiStore(
    (s) => s.setAccommodationScanning,
  );

  const preDragPacingSnapshot = useRef<ReturnType<
    typeof getUndoableSlice
  > | null>(null);

  async function handleDatesChange(
    newStart: string | null,
    newEnd: string | null,
  ) {
    const { startDate: previousStart, endDate: previousEnd } =
      useTripStore.getState();
    const undoToken = actions.updateDates(newStart, newEnd);
    if (!tripId) return;
    const claim = actions.claimSettings(["startDate", "endDate"]);

    // updateDates pushed an undo entry: a refused change must leave no trace in the
    // history, and must not undo a date change made while it was in flight.
    const rollback = () => {
      const previous = { startDate: previousStart, endDate: previousEnd };
      discardUndoEntry(
        undoToken,
        { startDate: newStart, endDate: newEnd },
        previous,
      );
      actions.revertSettings(claim, previous);
    };

    try {
      const pacing = getPacingState();
      const { data, error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId }, header: preconditionHeader(tripId) },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          startDate: newStart,
          endDate: newEnd,
          ...pacing,
        },
      });

      if (error) {
        rollback();
        reportApiError(response.status, error);
      } else {
        actions.settleSettings(claim);
        if (data) actions.setIsLocked(data.isLocked === true);
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      rollback();
      toast.error(t("errors.failedUpdateDates"));
    }
  }

  async function handleTitleChange(newTitle: string) {
    const previousTitle = useTripStore.getState().trip?.title;
    actions.updateTitle(newTitle);
    if (!tripId) return;
    const claim = actions.claimSettings(["title"]);

    // Unless a newer rename has replaced it meanwhile.
    const revertTitle = () => {
      if (previousTitle !== undefined) {
        actions.revertSettings(claim, { title: previousTitle });
      }
      actions.settleSettings(claim);
    };

    try {
      const pacing = getPacingState();
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId }, header: preconditionHeader(tripId) },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          title: newTitle,
          ...pacing,
        },
      });
      if (!response.ok) {
        reportApiError(response.status, error);
        revertTitle();
      } else {
        actions.settleSettings(claim);
      }
    } catch {
      // Title save is best-effort on a network failure: no toast, but never keep a title
      // the server does not have.
      revertTitle();
    }
  }

  async function patchPacingSettings(
    newFatigue: number,
    newElevation: number,
    newMaxDistance: number,
    newAverageSpeed: number,
    newEbikeMode: boolean,
    // When true, skip the recomputing skeleton: the change has already been
    // reflected locally (e.g. the e-bike toggle clears terrain alerts and the
    // stat row re-derives durations from `averageSpeed`), so the cards must
    // stay mounted with their content instead of waiting for a `stages_computed`
    // SSE that may never come for a purely local optimistic update.
    optimistic = false,
  ): Promise<boolean> {
    if (!tripId) return false;

    try {
      const { departureHour: dh, enabledAccommodationTypes: eat } =
        getPacingState();
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId }, header: preconditionHeader(tripId) },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          fatigueFactor: newFatigue,
          elevationPenalty: newElevation,
          maxDistancePerDay: newMaxDistance,
          averageSpeed: newAverageSpeed,
          ebikeMode: newEbikeMode,
          departureHour: dh,
          enabledAccommodationTypes: eat,
        },
      });

      if (error) {
        reportApiError(response.status, error);
        return false;
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
        if (optimistic) return true;
        // Mark every stage as recomputing so the timeline shows the shimmer
        // skeleton until the `stages_computed` Mercure event lands. The stages
        // are NOT wiped: clearing them flips `isTripLoaded` to false, unmounts
        // the whole trip view (toolbar, config, undo/redo) and defeats the
        // in-place merge that preserves accommodations/labels (use-mercure).
        const allStages = useTripStore.getState().stages;
        if (allStages.length > 0) {
          actions.startStageRecomputation(allStages.map((stage) => stage.id));
        }
        return true;
      }
    } catch {
      toast.error(t("errors.failedUpdatePacing"));
      return false;
    }
  }

  function handlePacingChange(
    newFatigue: number,
    newElevation: number,
    newMaxDistance: number,
    newAverageSpeed: number,
  ) {
    // Capture the pre-drag snapshot on the very first onChange of each gesture,
    // before any live-preview mutation touches the store.
    if (preDragPacingSnapshot.current === null) {
      preDragPacingSnapshot.current = getUndoableSlice(useTripStore.getState());
    }
    actions.updatePacingSettingsInternal(
      newFatigue,
      newElevation,
      newMaxDistance,
      newAverageSpeed,
    );
  }

  async function handlePacingCommit(
    newFatigue: number,
    newElevation: number,
    newMaxDistance: number,
    newAverageSpeed: number,
  ) {
    // Push the pre-drag snapshot so Ctrl+Z restores the value before the gesture.
    // For preset button clicks (no preceding onChange) fall back to current state,
    // which is still the pre-change value since updatePacingSettingsInternal runs after.
    const snapshot =
      preDragPacingSnapshot.current ??
      getUndoableSlice(useTripStore.getState());
    preDragPacingSnapshot.current = null;
    const undoToken = useTripTemporalStore.getState()._push(snapshot);
    actions.updatePacingSettingsInternal(
      newFatigue,
      newElevation,
      newMaxDistance,
      newAverageSpeed,
    );
    const claim = actions.claimSettings([
      "fatigueFactor",
      "elevationPenalty",
      "maxDistancePerDay",
      "averageSpeed",
    ]);
    const saved = await patchPacingSettings(
      newFatigue,
      newElevation,
      newMaxDistance,
      newAverageSpeed,
      getPacingState().ebikeMode,
    );
    if (!saved && tripId) {
      const previous = {
        fatigueFactor: snapshot.fatigueFactor,
        elevationPenalty: snapshot.elevationPenalty,
        maxDistancePerDay: snapshot.maxDistancePerDay,
        averageSpeed: snapshot.averageSpeed,
      };
      discardUndoEntry(
        undoToken,
        {
          fatigueFactor: newFatigue,
          elevationPenalty: newElevation,
          maxDistancePerDay: newMaxDistance,
          averageSpeed: newAverageSpeed,
        },
        previous,
      );
      actions.revertSettings(claim, previous);
    }
    actions.settleSettings(claim);
  }

  async function handleDepartureHourChange(newDepartureHour: number) {
    const previous = useTripStore.getState().departureHour;
    actions.setDepartureHour(newDepartureHour);
    if (!tripId) return;
    const claim = actions.claimSettings(["departureHour"]);

    try {
      const pacing = getPacingState();
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId }, header: preconditionHeader(tripId) },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          ...pacing,
          departureHour: newDepartureHour,
        },
      });

      if (error) {
        actions.revertSettings(claim, { departureHour: previous });
        reportApiError(response.status, error);
      } else {
        actions.settleSettings(claim);
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      actions.revertSettings(claim, { departureHour: previous });
      toast.error(t("errors.failedUpdatePacing"));
    }
  }

  async function handleEbikeModeChange(newEbikeMode: boolean) {
    const previousEbikeMode = useTripStore.getState().ebikeMode;
    // The terrain alerts cleared below, by stage identity, so a refusal puts back
    // only those and leaves any stage change made meanwhile alone.
    const clearedTerrain = new Map<string, StageData["alerts"]>();
    if (!newEbikeMode) {
      for (const stage of useTripStore.getState().stages) {
        const terrain = (stage.alerts as StageAlert[]).filter(
          (a) => a.group === "terrain",
        );
        if (terrain.length > 0) clearedTerrain.set(stage.id, terrain);
      }
    }
    actions.setEbikeMode(newEbikeMode);
    const claim = actions.claimSettings(["ebikeMode"]);
    if (!newEbikeMode) {
      const currentStages = useTripStore.getState().stages;
      currentStages.forEach((_, i) =>
        actions.updateStageAlerts(i, [], "terrain"),
      );
    }
    const pacing = getPacingState();
    // The toggle is applied optimistically in-place (alerts cleared above,
    // durations re-derived from the stat row): keep the cards mounted rather
    // than swapping them for the recomputing skeleton.
    const saved = await patchPacingSettings(
      pacing.fatigueFactor,
      pacing.elevationPenalty,
      pacing.maxDistancePerDay,
      pacing.averageSpeed,
      newEbikeMode,
      true,
    );
    // A toggle made while this one was in flight owns the mode and the alerts now.
    if (!saved && tripId) {
      const reverted = actions.revertSettings(claim, {
        ebikeMode: previousEbikeMode,
      });
      if (reverted.includes("ebikeMode")) {
        useTripStore.getState().stages.forEach((stage, i) => {
          const terrain = clearedTerrain.get(stage.id);
          if (terrain) actions.updateStageAlerts(i, terrain, "terrain");
        });
      }
    }
    actions.settleSettings(claim);
  }

  async function handleAccommodationTypesChange(newTypes: AccommodationType[]) {
    const previous = useTripStore.getState().enabledAccommodationTypes;
    actions.setEnabledAccommodationTypes(newTypes);
    if (!tripId) return;
    const claim = actions.claimSettings(["enabledAccommodationTypes"]);

    try {
      const pacing = getPacingState();
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId }, header: preconditionHeader(tripId) },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          ...pacing,
          enabledAccommodationTypes: newTypes,
        },
      });

      if (error) {
        actions.revertSettings(claim, { enabledAccommodationTypes: previous });
        reportApiError(response.status, error);
      } else {
        actions.settleSettings(claim);
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      actions.revertSettings(claim, { enabledAccommodationTypes: previous });
      toast.error(t("errors.failedUpdateAccommodationTypes"));
    }
  }

  async function handleDuplicateTrip(): Promise<string | null> {
    if (!tripId || !trip) return null;

    try {
      const result = await duplicateTrip(tripId);
      if (!result) {
        toast.error(t("config.duplicateFailed"));
        return null;
      }

      toast.success(t("config.duplicateSuccess"));
      router.push(`/trips/${result.id}`);
      return result.id;
    } catch (err) {
      if (isNetworkError(err)) {
        toast.error(t("errors.networkError"));
      } else {
        toast.error(t("config.duplicateFailed"));
      }
      return null;
    }
  }

  /**
   * Delete the loaded trip from the trip view itself (recette #649). Reuses the
   * same `DELETE /trips/{id}` endpoint as the "Mes voyages" list, then clears
   * the local store and navigates back to the trips list.
   *
   * @returns true on success, false otherwise.
   */
  async function handleDeleteTrip(): Promise<boolean> {
    if (!tripId) return false;
    try {
      const ok = await deleteTrip(tripId);
      if (!ok) {
        toast.error(t("config.deleteFailed"));
        return false;
      }
      toast.success(t("config.deleteSuccess"));
      actions.clearTrip();
      useUiStore.getState().setProcessing(false);
      useUiStore.getState().setAccommodationScanning(false);
      useUiStore.getState().setConfigPanelOpen(false);
      router.push("/trips");
      return true;
    } catch (err) {
      if (isNetworkError(err)) {
        toast.error(t("errors.networkError"));
      } else {
        toast.error(t("config.deleteFailed"));
      }
      return false;
    }
  }

  return {
    handleDatesChange,
    handleTitleChange,
    handlePacingChange,
    handlePacingCommit,
    handleDepartureHourChange,
    handleEbikeModeChange,
    handleAccommodationTypesChange,
    handleDuplicateTrip,
    handleDeleteTrip,
  };
}
