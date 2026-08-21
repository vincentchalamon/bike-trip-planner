"use client";

import { useEffect, useRef, useState } from "react";
import { toast } from "@/components/ui/sonner";
import { useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import { useShallow } from "zustand/react/shallow";
import {
  useTripStore,
  useTripTemporalStore,
  getUndoableSlice,
} from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { useMercure } from "@/hooks/use-mercure";
import {
  apiClient,
  parseApiError,
  localizedApiErrorMessage,
  isNetworkError,
  uploadGpxFile,
  scanAccommodations,
  addManualAccommodation,
  addPoiWaypointToRoute,
  duplicateTrip,
  deleteTrip,
  launchTripAnalysis,
  applyBatchRecompute,
} from "@/lib/api/client";
import { getRandomTripName } from "@/lib/trip-utils";
import { trackEvent, type PlausibleEvent } from "@/lib/plausible";
import {
  MAX_ACCOMMODATION_RADIUS_KM,
  ACCOMMODATION_RADIUS_STEP_KM,
  DEFAULT_ACCOMMODATION_RADIUS_KM,
} from "@btp/core/constants";
import { EMPTY_RESUPPLY } from "@btp/core";
import type { StageData } from "@btp/core";
import type { AccommodationType } from "@/lib/accommodation-types";
import type { ManualAccommodationInput } from "@/components/manual-accommodation-form";

/** Map a source URL to its Plausible import event (null if unrecognised). */
export function importEventForUrl(url: string): PlausibleEvent | null {
  if (/komoot\.com\//.test(url)) return "import_komoot";
  if (/strava\.com\//.test(url)) return "import_strava";
  if (/ridewithgps\.com\//.test(url)) return "import_rwgps";
  return null;
}

/**
 * Last-resort delay after which a recompute that never fully settled (a lost
 * or obsolete `stage_updated`, e.g. after the day count changed) has its
 * `processing` overlay force-lifted. Generous enough to outlast a real
 * recompute + enrichment pass so it only fires on a genuinely stuck run (#840).
 */
const RECOMPUTE_OVERLAY_TIMEOUT_MS = 30000;

/** Read current pacing + config state from the store without subscribing. */
function getPacingState() {
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

export function useTripPlanner() {
  const t = useTranslations();
  const router = useRouter();

  // Group 1: Trip data — re-renders when trip metadata or stages change
  const {
    trip,
    totalDistance,
    totalElevation,
    totalElevationLoss,
    stages,
    startDate,
    endDate,
    isLocked,
    outOfZone,
  } = useTripStore(
    useShallow((s) => ({
      trip: s.trip,
      totalDistance: s.totalDistance,
      totalElevation: s.totalElevation,
      totalElevationLoss: s.totalElevationLoss,
      stages: s.stages,
      startDate: s.startDate,
      endDate: s.endDate,
      isLocked: s.isLocked,
      outOfZone: s.outOfZone,
    })),
  );

  // Group 2: Pacing settings — re-renders when pacing config changes
  const {
    fatigueFactor,
    elevationPenalty,
    maxDistancePerDay,
    averageSpeed,
    ebikeMode,
    departureHour,
    enabledAccommodationTypes,
  } = useTripStore(
    useShallow((s) => ({
      fatigueFactor: s.fatigueFactor,
      elevationPenalty: s.elevationPenalty,
      maxDistancePerDay: s.maxDistancePerDay,
      averageSpeed: s.averageSpeed,
      ebikeMode: s.ebikeMode,
      departureHour: s.departureHour,
      enabledAccommodationTypes: s.enabledAccommodationTypes,
    })),
  );

  // Group 3: Store actions — stable references, single subscription
  const actions = useTripStore(
    useShallow((s) => ({
      setTrip: s.setTrip,
      updateRouteData: s.updateRouteData,
      updateTitle: s.updateTitle,
      updateDates: s.updateDates,
      clearTrip: s.clearTrip,
      addLocalAccommodation: s.addLocalAccommodation,
      removeLocalAccommodation: s.removeLocalAccommodation,
      updateLocalAccommodation: s.updateLocalAccommodation,
      selectAccommodation: s.selectAccommodation,
      deselectAccommodation: s.deselectAccommodation,
      deleteStage: s.deleteStage,
      insertRestDay: s.insertRestDay,
      insertStagePlaceholder: s.insertStagePlaceholder,
      updatePacingSettingsInternal: s.updatePacingSettingsInternal,
      setEbikeMode: s.setEbikeMode,
      setEnabledAccommodationTypes: s.setEnabledAccommodationTypes,
      updateStageAlerts: s.updateStageAlerts,
      setIsLocked: s.setIsLocked,
      setDepartureHour: s.setDepartureHour,
      startStageRecomputation: s.startStageRecomputation,
      queueModification: s.queueModification,
      cancelAllModifications: s.cancelAllModifications,
      clearPendingModifications: s.clearPendingModifications,
    })),
  );

  const pendingModifications = useTripStore((s) => s.pendingModifications);
  const [isBatchApplying, setIsBatchApplying] = useState(false);

  // UI store
  const isProcessing = useUiStore((s) => s.isProcessing);
  const setProcessing = useUiStore((s) => s.setProcessing);
  const setAccommodationScanning = useUiStore(
    (s) => s.setAccommodationScanning,
  );

  const [newAccKey, setNewAccKey] = useState<string | null>(null);
  const preDragPacingSnapshot = useRef<ReturnType<
    typeof getUndoableSlice
  > | null>(null);
  const recomputeTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const tripId = trip?.id ?? null;
  useMercure(tripId);

  // Chat history is scoped to a single trip session. Wipe it whenever the
  // user switches trip so messages from trip A don't bleed into trip B's
  // panel after navigation.
  useEffect(() => {
    if (!tripId) return;
    useUiStore.getState().clearHistory();
  }, [tripId]);

  // Clear the recompute safety-net timer on unmount so it can't fire against a
  // torn-down view (#840).
  // Clear any pending safety-net timer on unmount and whenever the active trip
  // changes, so a timer armed for one trip can never fire against another.
  useEffect(
    () => () => {
      if (recomputeTimerRef.current) clearTimeout(recomputeTimerRef.current);
    },
    [tripId],
  );

  async function handleMagicLink(sourceUrl: string) {
    actions.clearTrip();
    setProcessing(true);

    try {
      const pacing = getPacingState();
      const { data, error, response } = await apiClient.POST("/trips", {
        body: {
          sourceUrl,
          ...pacing,
          startDate: useTripStore.getState().startDate,
        },
      });

      if (error || !data) {
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
        setProcessing(false);
        setAccommodationScanning(false);
        return;
      }

      actions.setIsLocked(data.isLocked === true);
      actions.setTrip({
        id: data.id ?? "",
        title: getRandomTripName(),
        sourceUrl,
      });
      const importEvent = importEventForUrl(sourceUrl);
      if (importEvent) trackEvent(importEvent);
      trackEvent("trip_created", { source: importEvent ?? "url" });
      toast.success(t("planner.tripSavedToAccount"));
      router.push(`/trips/${data.id ?? ""}`);
    } catch (err) {
      if (isNetworkError(err)) {
        toast.error(t("errors.networkError"));
      } else {
        toast.error(t("errors.unexpectedError"));
      }
      setProcessing(false);
      setAccommodationScanning(false);
    }
  }

  async function handleGpxUpload(file: File) {
    actions.clearTrip();
    setProcessing(true);

    try {
      const pacing = getPacingState();
      const { data, error } = await uploadGpxFile(file, {
        ...pacing,
        startDate: useTripStore.getState().startDate,
      });

      if (error || !data) {
        toast.error(t("errors.gpxUploadFailed"));
        setProcessing(false);
        setAccommodationScanning(false);
        return;
      }

      actions.setTrip({
        id: data.id,
        title: data.title ?? file.name.replace(/\.gpx$/i, ""),
        sourceUrl: "",
      });
      trackEvent("import_gpx");
      trackEvent("trip_created", { source: "gpx" });
      toast.success(t("planner.tripSavedToAccount"));
      // Navigate to /trips/{id} like the magic-link flow (#729): the planner
      // re-hydrates from the detail endpoint and the async Mercure lifecycle
      // (route_parsed → stages_computed → preview) drives the wizard. Without
      // this the GPX flow stayed on /trips/new and the preview gate ("Lancer
      // l'analyse") was unreachable.
      router.push(`/trips/${data.id}`);
    } catch (err) {
      if (isNetworkError(err)) {
        toast.error(t("errors.networkError"));
      } else {
        toast.error(t("errors.unexpectedError"));
      }
      setProcessing(false);
      setAccommodationScanning(false);
    }
  }

  async function handleDatesChange(
    newStart: string | null,
    newEnd: string | null,
  ) {
    actions.updateDates(newStart, newEnd);
    if (!tripId) return;

    try {
      const pacing = getPacingState();
      const { data, error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          startDate: newStart,
          endDate: newEnd,
          ...pacing,
        },
      });

      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
      } else {
        if (data) actions.setIsLocked(data.isLocked === true);
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedUpdateDates"));
    }
  }

  async function handleTitleChange(newTitle: string) {
    actions.updateTitle(newTitle);
    if (!tripId) return;

    try {
      const pacing = getPacingState();
      await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          title: newTitle,
          ...pacing,
        },
      });
    } catch {
      // Title save is best-effort — don't show error toast for this
    }
  }

  async function handleDeleteStage(index: number) {
    if (!tripId) return;

    const currentStages = useTripStore.getState().stages;
    const isRestDay = currentStages[index]?.isRestDay ?? false;
    const snapshot = [...currentStages];
    actions.deleteStage(index);

    try {
      const { error, response } = await apiClient.DELETE(
        "/trips/{tripId}/stages/{index}",
        {
          params: { path: { tripId, index: String(index) } },
        },
      );
      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
        useTripTemporalStore.getState()._pop();
        useTripStore.getState().setStages(snapshot);
      } else {
        setProcessing(true);
        if (!isRestDay) setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedDeleteStage"));
      useTripTemporalStore.getState()._pop();
      useTripStore.getState().setStages(snapshot);
    }
  }

  async function handleInsertRestDay(afterIndex: number) {
    if (!tripId) return;

    const snapshot = [...useTripStore.getState().stages];
    actions.insertRestDay(afterIndex);

    try {
      const { response } = await apiClient.POST(
        "/trips/{tripId}/stages/{index}/rest-day",
        {
          params: {
            path: { tripId, index: String(afterIndex) },
          },
          parseAs: "json",
        },
      );
      if (!response.ok) {
        toast.error(t("errors.failedInsertRestDay"));
        useTripTemporalStore.getState()._pop();
        useTripStore.getState().setStages(snapshot);
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error(t("errors.failedInsertRestDay"));
      useTripTemporalStore.getState()._pop();
      useTripStore.getState().setStages(snapshot);
    }
  }

  async function handleAddStage(afterIndex: number) {
    if (!tripId) return;

    const currentStages = useTripStore.getState().stages;
    const prevStage = currentStages[afterIndex];
    const nextStage = currentStages[afterIndex + 1];
    const startPoint = prevStage?.endPoint ?? prevStage?.startPoint;
    const endPoint = nextStage?.startPoint ?? prevStage?.endPoint;

    if (!startPoint || !endPoint) {
      toast.error(t("errors.failedAddStage"));
      return;
    }

    const placeholder: StageData = {
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
    actions.insertStagePlaceholder(afterIndex, placeholder);

    try {
      const { error, response } = await apiClient.POST(
        "/trips/{tripId}/stages",
        {
          params: { path: { tripId } },
          body: { position: afterIndex + 1, startPoint, endPoint },
        },
      );
      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
        useTripTemporalStore.getState()._pop();
        useTripStore.getState().setStages(currentStages);
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedAddStage"));
      useTripTemporalStore.getState()._pop();
      useTripStore.getState().setStages(currentStages);
    }
  }

  /**
   * Arm a last-resort timer that lifts the `processing` overlay if the current
   * recompute never fully settles (lost/obsolete `stage_updated`, or a day-count
   * change that leaves marked indices without a matching event). The timer
   * captures the recompute token (and the trip it belongs to) at arm time and
   * no-ops if a newer edit has since bumped the token or the user switched
   * trips — so overlapping edits (or a trip switch) can't clear each other's
   * overlay (#840).
   */
  function armRecomputeSafetyNet() {
    if (recomputeTimerRef.current) clearTimeout(recomputeTimerRef.current);
    const version = useTripStore.getState().recomputeVersion;
    const armedTripId = useTripStore.getState().trip?.id ?? null;
    recomputeTimerRef.current = setTimeout(() => {
      recomputeTimerRef.current = null;
      const s = useTripStore.getState();
      // Bail if a newer recompute superseded this one, everything already
      // settled, or the user switched trips since arming — otherwise a stale
      // timer from a previous trip could force-clear a different trip's
      // legitimately in-flight overlay.
      if (
        s.recomputeVersion !== version ||
        s.recomputingStages.size === 0 ||
        (s.trip?.id ?? null) !== armedTripId
      ) {
        return;
      }
      s.clearRecomputingStages();
      setProcessing(false);
      setAccommodationScanning(false);
    }, RECOMPUTE_OVERLAY_TIMEOUT_MS);
  }

  async function handleDistanceChange(index: number, distance: number) {
    if (!tripId) return;

    // Capture state before the mutation so we can push it on success.
    const snapshot = getUndoableSlice(useTripStore.getState());

    // Show the per-stage skeleton immediately — BEFORE awaiting the PATCH — so
    // the edited card and every subsequent one indicate loading and block
    // further edits while the backend re-splits. Otherwise the card keeps
    // showing the old distance for the whole request round-trip, only updating
    // when the `stage_updated` events land (recette: "la distance reste
    // identique un moment"). The backend re-splits from `index` onward
    // (StageUpdateProcessor → RecalculateStages over range(index, count-1)), so
    // the shimmer covers the same range (#840).
    const stageCount = useTripStore.getState().stages.length;
    setProcessing(true);
    setAccommodationScanning(true);
    actions.startStageRecomputation(
      Array.from(
        { length: Math.max(1, stageCount - index) },
        (_, k) => index + k,
      ),
    );
    armRecomputeSafetyNet();

    try {
      const { error, response } = await apiClient.PATCH(
        "/trips/{tripId}/stages/{index}",
        {
          params: { path: { tripId, index: String(index) } },
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
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
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
  ) {
    if (!tripId) return;

    try {
      const { departureHour: dh, enabledAccommodationTypes: eat } =
        getPacingState();
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
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
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
        if (optimistic) return;
        // Mark every stage as recomputing so the timeline shows the shimmer
        // skeleton until the `stages_computed` Mercure event lands. The stages
        // are NOT wiped: clearing them flips `isTripLoaded` to false, unmounts
        // the whole trip view (toolbar, config, undo/redo) and defeats the
        // in-place merge that preserves accommodations/labels (use-mercure).
        const stageCount = useTripStore.getState().stages.length;
        if (stageCount > 0) {
          actions.startStageRecomputation(
            Array.from({ length: stageCount }, (_, i) => i),
          );
        }
      }
    } catch {
      toast.error(t("errors.failedUpdatePacing"));
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
    useTripTemporalStore.getState()._push(snapshot);
    actions.updatePacingSettingsInternal(
      newFatigue,
      newElevation,
      newMaxDistance,
      newAverageSpeed,
    );
    await patchPacingSettings(
      newFatigue,
      newElevation,
      newMaxDistance,
      newAverageSpeed,
      getPacingState().ebikeMode,
    );
  }

  async function handleDepartureHourChange(newDepartureHour: number) {
    actions.setDepartureHour(newDepartureHour);
    if (!tripId) return;

    try {
      const pacing = getPacingState();
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          ...pacing,
          departureHour: newDepartureHour,
        },
      });

      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedUpdatePacing"));
    }
  }

  async function handleEbikeModeChange(newEbikeMode: boolean) {
    actions.setEbikeMode(newEbikeMode);
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
    await patchPacingSettings(
      pacing.fatigueFactor,
      pacing.elevationPenalty,
      pacing.maxDistancePerDay,
      pacing.averageSpeed,
      newEbikeMode,
      true,
    );
  }

  async function handleAccommodationTypesChange(newTypes: AccommodationType[]) {
    const previous = useTripStore.getState().enabledAccommodationTypes;
    actions.setEnabledAccommodationTypes(newTypes);
    if (!tripId) return;

    try {
      const pacing = getPacingState();
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          ...pacing,
          enabledAccommodationTypes: newTypes,
        },
      });

      if (error) {
        actions.setEnabledAccommodationTypes(previous);
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      actions.setEnabledAccommodationTypes(previous);
      toast.error(t("errors.failedUpdateAccommodationTypes"));
    }
  }

  async function handleExpandAccommodationRadius(
    stageIndex: number,
    currentRadiusKm: number,
  ): Promise<boolean> {
    if (!tripId) return false;

    const nextRadius = currentRadiusKm + ACCOMMODATION_RADIUS_STEP_KM;
    if (nextRadius > MAX_ACCOMMODATION_RADIUS_KM) return false;

    try {
      const ok = await scanAccommodations(tripId, nextRadius, stageIndex);
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

  async function handleAddPoiWaypoint(
    stageIndex: number,
    poiLat: number,
    poiLon: number,
  ) {
    if (!tripId) return;

    // Inserting a POI waypoint re-routes the stage via Valhalla, which has no
    // tiles outside the provisioned coverage area — block it for out-of-zone trips.
    if (outOfZone) {
      toast.error(t("outOfZone.editDisabled"));

      return;
    }

    try {
      const ok = await addPoiWaypointToRoute(
        tripId,
        stageIndex,
        poiLat,
        poiLon,
      );
      if (ok) {
        setProcessing(true);
      } else {
        toast.error(t("errors.unexpectedError"));
      }
    } catch {
      toast.error(t("errors.unexpectedError"));
    }
  }

  /**
   * Re-run the full enrichment pipeline for the currently-loaded trip: the
   * rider asked for a tracé-wide modification, so weather is recomputed on top
   * of the already-displayed trip view (ADR-043 — no wizard gate). The weather
   * block spinner is flipped to `running` so the affected cards show their
   * loading state until the matching Mercure events land. Errors surface as
   * toasts and the trip view stays put so the user can retry.
   */
  async function handleLaunchAnalysis(): Promise<boolean> {
    if (!tripId) return false;

    try {
      const ok = await launchTripAnalysis(tripId);
      if (!ok) {
        toast.error(t("tripPreview.analysisLaunchFailed"));
        return false;
      }
      setProcessing(true);
      setAccommodationScanning(true);
      useUiStore.getState().setBlockStatus("weather", "running");
      return true;
    } catch (err) {
      if (isNetworkError(err)) {
        toast.error(t("errors.networkError"));
      } else {
        toast.error(t("tripPreview.analysisLaunchFailed"));
      }
      return false;
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

  /**
   * Bounce the rider back to Acte 2 (full re-analysis). Mirrors
   * {@link handleLaunchAnalysis} but is invoked from the analysis card's
   * "Relancer l'analyse" button.
   */
  async function relaunchFullAnalysis(): Promise<boolean> {
    return handleLaunchAnalysis();
  }

  const [isShareModalOpen, setShareModalOpen] = useState(false);

  function handleShareTrip(): void {
    if (!tripId || !trip) return;
    setShareModalOpen(true);
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
    if (!tripId) return false;

    if (outOfZone) {
      toast.error(t("outOfZone.editDisabled"));
      return false;
    }

    try {
      const { ok, status } = await addManualAccommodation(
        tripId,
        stageIndex,
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
      const affectedIndices = [stageIndex];
      if (stageIndex + 1 < useTripStore.getState().stages.length) {
        affectedIndices.push(stageIndex + 1);
      }
      actions.startStageRecomputation(affectedIndices);
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
    if (!tripId) return;

    const currentStages = useTripStore.getState().stages;
    const acc = currentStages[stageIndex]?.accommodations[accIndex];
    if (!acc) return;

    const nextStageIndex =
      stageIndex + 1 < currentStages.length ? stageIndex + 1 : null;

    // Optimistic update
    actions.selectAccommodation(stageIndex, accIndex, nextStageIndex);

    try {
      const { error, response } = await apiClient.PATCH(
        "/trips/{tripId}/stages/{index}/accommodation",
        {
          params: { path: { tripId, index: String(stageIndex) } },
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
            stageIndex,
          );
          if (ok) {
            setAccommodationScanning(true);
          } else {
            toast.error(t("errors.unexpectedError"));
          }
        } else {
          const apiError = parseApiError(response.status, error);
          toast.error(apiError.message);
          // Rollback on error: restore accommodations from store snapshot
          useTripStore.getState().setStages([...currentStages]);
        }
      } else {
        setProcessing(true);
        // Mark affected stages as recomputing: the selected stage and the
        // next one (its startPoint may have shifted to the accommodation).
        const affectedIndices = [stageIndex];
        if (nextStageIndex !== null) affectedIndices.push(nextStageIndex);
        actions.startStageRecomputation(affectedIndices);
        trackEvent("accommodation_selected", { type: acc.type });
      }
    } catch {
      toast.error(t("errors.failedSelectAccommodation"));
      useTripStore.getState().setStages([...currentStages]);
    }
  }

  async function handleDeselectAccommodation(stageIndex: number) {
    if (!tripId) return;

    const currentStages = useTripStore.getState().stages;
    // Optimistic update
    actions.deselectAccommodation(stageIndex);

    try {
      const { error, response } = await apiClient.PATCH(
        "/trips/{tripId}/stages/{index}/accommodation",
        {
          params: { path: { tripId, index: String(stageIndex) } },
          headers: { "Content-Type": "application/merge-patch+json" },
          body: {
            selectedAccommodationLat: null,
            selectedAccommodationLon: null,
          },
        },
      );
      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
        useTripStore.getState().setStages([...currentStages]);
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
        // Mark affected stages as recomputing: the deselected stage and the
        // next one (its startPoint reverts to original after deselection).
        const affectedIndices: number[] = [stageIndex];
        const nextIdx = stageIndex + 1;
        if (nextIdx < useTripStore.getState().stages.length) {
          affectedIndices.push(nextIdx);
        }
        actions.startStageRecomputation(affectedIndices);
      }
    } catch {
      toast.error(t("errors.failedDeselectAccommodation"));
      useTripStore.getState().setStages([...currentStages]);
    }
  }

  async function handleApplyBatch() {
    if (!tripId || pendingModifications.length === 0) return;

    setIsBatchApplying(true);
    try {
      const ok = await applyBatchRecompute(tripId, pendingModifications);
      if (ok) {
        actions.clearPendingModifications();
        setProcessing(true);
        setAccommodationScanning(true);
        // Mark all stages affected by pending modifications as recomputing
        const affectedIndices = new Set<number>();
        for (const mod of pendingModifications) {
          if (mod.stageIndex !== null) {
            if (mod.type === "distance") {
              // Distance recomputes the modified stage and every subsequent one
              // (mirrors ComputationDependencyResolver.resolve on the backend).
              for (let i = mod.stageIndex; i < stages.length; i++) {
                affectedIndices.add(i);
              }
            } else {
              affectedIndices.add(mod.stageIndex);
              const nextIdx = mod.stageIndex + 1;
              if (nextIdx < stages.length) {
                affectedIndices.add(nextIdx);
              }
            }
          } else {
            // Trip-level modifications (dates, pacing) affect all stages
            for (let i = 0; i < stages.length; i++) {
              affectedIndices.add(i);
            }
          }
        }
        if (affectedIndices.size > 0) {
          actions.startStageRecomputation(Array.from(affectedIndices));
        }
      } else {
        toast.error(t("modificationQueue.failedApply"));
      }
    } catch {
      toast.error(t("modificationQueue.failedApply"));
    } finally {
      setIsBatchApplying(false);
    }
  }

  function handleCancelBatch() {
    actions.cancelAllModifications();
  }

  const firstStage = stages[0];
  const firstWeather = firstStage?.weather ?? null;
  const isWeatherLoading = isProcessing && stages.length > 0 && !firstWeather;

  return {
    trip,
    isLocked,
    outOfZone,
    totalDistance,
    totalElevation,
    totalElevationLoss,
    stages,
    startDate,
    endDate,
    isProcessing,
    newAccKey,
    firstWeather,
    isWeatherLoading,
    fatigueFactor,
    elevationPenalty,
    maxDistancePerDay,
    averageSpeed,
    ebikeMode,
    departureHour,
    enabledAccommodationTypes,
    handleAccommodationTypesChange,
    handleTitleChange,
    updateLocalAccommodation: actions.updateLocalAccommodation,
    removeLocalAccommodation: actions.removeLocalAccommodation,
    handleMagicLink,
    handleGpxUpload,
    handleDatesChange,
    handleDeleteStage,
    handleAddStage,
    handleDistanceChange,
    handlePacingChange,
    handlePacingCommit,
    handleEbikeModeChange,
    handleDepartureHourChange,
    handleAddManualAccommodation,
    handleSelectAccommodation,
    handleDeselectAccommodation,
    handleExpandAccommodationRadius,
    handleInsertRestDay,
    handleAddPoiWaypoint,
    handleDuplicateTrip,
    handleDeleteTrip,
    handleLaunchAnalysis,
    handleShareTrip,
    isShareModalOpen,
    setShareModalOpen,
    clearNewAccKey: () => setNewAccKey(null),
    pendingModifications,
    isBatchApplying,
    handleApplyBatch,
    handleCancelBatch,
    queueModification: actions.queueModification,
    relaunchFullAnalysis,
  };
}
