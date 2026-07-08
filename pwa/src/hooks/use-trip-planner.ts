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
  addPoiWaypointToRoute,
  duplicateTrip,
  deleteTrip,
  launchTripAnalysis,
  applyBatchRecompute,
  sendTripChat,
  type TripChatResponseBody,
} from "@/lib/api/client";
import { getRandomTripName } from "@/lib/trip-utils";
import { trackEvent, type PlausibleEvent } from "@/lib/plausible";
import {
  MAX_ACCOMMODATION_RADIUS_KM,
  ACCOMMODATION_RADIUS_STEP_KM,
  DEFAULT_ACCOMMODATION_RADIUS_KM,
} from "@/lib/accommodation-constants";
import type { AccommodationData, StageData } from "@/lib/validation/schemas";
import type { AccommodationType } from "@/lib/accommodation-types";

/** Map a source URL to its Plausible import event (null if unrecognised). */
export function importEventForUrl(url: string): PlausibleEvent | null {
  if (/komoot\.com\//.test(url)) return "import_komoot";
  if (/strava\.com\//.test(url)) return "import_strava";
  if (/ridewithgps\.com\//.test(url)) return "import_rwgps";
  return null;
}

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
  const [mercureToken, setMercureToken] = useState<string | null>(null);
  const preDragPacingSnapshot = useRef<ReturnType<
    typeof getUndoableSlice
  > | null>(null);
  const chatAbortRef = useRef<AbortController | null>(null);

  const tripId = trip?.id ?? null;
  useMercure(tripId, mercureToken);

  // Chat history is scoped to a single trip session. Wipe it whenever the
  // user switches trip so messages from trip A don't bleed into trip B's
  // panel after navigation. Also abort any chat request still in flight so
  // the LLM server doesn't keep generating tokens for a panel that's gone.
  useEffect(() => {
    if (!tripId) return;
    chatAbortRef.current?.abort();
    chatAbortRef.current = null;
    useUiStore.getState().clearHistory();
  }, [tripId]);

  async function handleMagicLink(sourceUrl: string) {
    actions.clearTrip();
    setMercureToken(null);
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
      const token = response.headers.get("X-Mercure-Token");
      if (token) setMercureToken(token);
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

  /**
   * Create a trip from a free-form natural-language brief (B2, ADR-042). Mirrors
   * {@link handleMagicLink} but POSTs to `/trips/ai-generate`: the LLM call,
   * geocoding and Valhalla routing run on the worker, so the same async Mercure
   * lifecycle (route_parsed → stages_computed → preview) drives the wizard.
   * Generation-specific failures (out-of-zone, unparseable, unavailable, ...)
   * surface as Mercure `validation_error` toasts via {@link useMercure}.
   */
  async function handleAiGeneration(brief: string) {
    actions.clearTrip();
    setMercureToken(null);
    setProcessing(true);

    try {
      const { data, error, response } = await apiClient.POST(
        "/trips/ai-generate",
        { body: { brief } },
      );

      if (error || !data) {
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
        setProcessing(false);
        setAccommodationScanning(false);
        return;
      }

      actions.setIsLocked(data.isLocked === true);
      const token = response.headers.get("X-Mercure-Token");
      if (token) setMercureToken(token);
      actions.setTrip({
        id: data.id ?? "",
        title: getRandomTripName(),
        sourceUrl: "",
      });
      trackEvent("trip_created", { source: "ai" });
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
    setMercureToken(null);
    setProcessing(true);

    try {
      const pacing = getPacingState();
      const { data, error, response } = await uploadGpxFile(file, {
        ...pacing,
        startDate: useTripStore.getState().startDate,
      });

      if (error || !data) {
        toast.error(t("errors.gpxUploadFailed"));
        setProcessing(false);
        setAccommodationScanning(false);
        return;
      }

      const gpxToken = response?.headers.get("X-Mercure-Token");
      if (gpxToken) setMercureToken(gpxToken);
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
      pois: [],
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

  async function handleDistanceChange(index: number, distance: number) {
    if (!tripId) return;

    // Capture state before the mutation so we can push it on success.
    const snapshot = getUndoableSlice(useTripStore.getState());

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
        const apiError = parseApiError(response.status, error);
        toast.error(localizedApiErrorMessage(apiError, t));
      } else {
        // Push snapshot only after a successful PATCH to avoid phantom undo entries
        useTripTemporalStore.getState()._push(snapshot);
        setProcessing(true);
        setAccommodationScanning(true);
        // Mark this stage as recomputing so the shimmer skeleton appears.
        actions.startStageRecomputation([index]);
      }
    } catch {
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
   * Re-run the full enrichment pipeline for the currently-loaded trip. Only
   * reached from the chat `change_route` action (see {@link relaunchFullAnalysis}):
   * the rider asked for a tracé-wide modification, so weather + AI are recomputed
   * on top of the already-displayed trip view (ADR-043 — no wizard gate). The
   * per-block spinners are flipped to `running` so the affected cards show their
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
      // Clear stale AI overview so the card does not show outdated data
      // while the new analysis is in flight.
      useTripStore.getState().setAiOverview(null);
      setAccommodationScanning(true);
      useUiStore.getState().setBlockStatus("weather", "running");
      useUiStore.getState().setBlockStatus("ai", "running");
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
   * Dispatch a chat turn to `POST /trips/{id}/ai-chat`, append both the user
   * message and the assistant reply to {@link useUiStore.chatHistory}, and
   * — when the backend dispatched an inline recomputation — flag the impacted
   * stages as `recomputing` so the timeline shows the shimmer skeleton until
   * the matching `stage_updated` Mercure events land.
   *
   * Issue #311: actionable replies carry `impactedStageNumbers` (1-indexed)
   * and a `requiresFullAnalysis` flag. The latter triggers a bounce back to
   * Acte 2 because the rider asked for a tracé-wide modification.
   */
  async function sendChatMessage(
    text: string,
    position?: { lat: number; lon: number; bearing?: number | null } | null,
  ): Promise<TripChatResponseBody | null> {
    const trimmed = text.trim();
    if (!trimmed) return null;
    if (trimmed.length > 2000) return null;
    if (!tripId) return null;

    const uiStore = useUiStore.getState();
    uiStore.appendMessage({
      role: "user",
      content: trimmed,
      ts: Date.now(),
    });
    uiStore.setChatSending(true);

    // Track the in-flight request so the tripId useEffect can abort it on a
    // trip switch. Any previous controller belongs to a settled request — we
    // overwrite it without aborting (the request already completed).
    const controller = new AbortController();
    chatAbortRef.current = controller;

    try {
      const { data, error, errorCode, status } = await sendTripChat(
        tripId,
        {
          message: trimmed,
          context: { currentStage: uiStore.currentContext.currentStage },
          ...(position ? { position } : {}),
        },
        controller.signal,
      );

      // Drop the response if the user switched trips while it was in-flight —
      // appending it would interleave trip A's reply into trip B's panel.
      if (useTripStore.getState().trip?.id !== tripId) {
        return null;
      }

      if (error || !data) {
        // An actionable provider-config error (invalid token / exhausted quota,
        // #761) is surfaced as a settings-CTA banner rather than a misleading
        // "retry" bubble — mirrors the brief chat (ai-chat-card).
        const configErrorKey =
          status === 422 && errorCode === "ai_invalid_token"
            ? "errorInvalidToken"
            : status === 422 && errorCode === "ai_quota_exceeded"
              ? "errorQuotaExceeded"
              : null;
        if (configErrorKey) {
          useUiStore.getState().setChatConfigError(configErrorKey);
          return null;
        }

        const message =
          status === 429
            ? t("aiBubble.errorRateLimit")
            : status === 503
              ? t("aiBubble.errorUnavailable")
              : t("aiBubble.errorGeneric");
        useUiStore.getState().appendMessage({
          role: "assistant",
          content: message,
          ts: Date.now(),
        });
        return null;
      }

      // A successful turn clears any lingering config-error banner.
      useUiStore.getState().setChatConfigError(null);
      useUiStore.getState().appendMessage({
        role: "assistant",
        content: data.response,
        ts: Date.now(),
        ...(data.pois && data.pois.length > 0 ? { pois: data.pois } : {}),
      });

      // Inline recomputation: surface the shimmer skeleton on the impacted
      // stage cards until the matching `stage_updated` SSE event clears it.
      const impactedStages = data.impactedStageNumbers ?? [];
      if (data.dispatched && impactedStages.length > 0) {
        const indices = impactedStages
          .filter((n): n is number => Number.isInteger(n) && n > 0)
          .map((n) => n - 1);
        if (indices.length > 0) {
          actions.startStageRecomputation(indices);
          setProcessing(true);
        }
      }

      return data;
    } catch (err) {
      // Drop the error message if the user switched trips while the request
      // was in flight — appending it would surface the previous trip's
      // failure inside the new trip's panel.
      if (useTripStore.getState().trip?.id !== tripId) {
        return null;
      }

      const message = isNetworkError(err)
        ? t("aiBubble.errorNetwork")
        : t("aiBubble.errorGeneric");
      useUiStore.getState().appendMessage({
        role: "assistant",
        content: message,
        ts: Date.now(),
      });
      return null;
    } finally {
      // Only flip our own in-flight indicator off — a trip switch may have
      // already started a fresh request whose flag we must not clobber.
      if (useTripStore.getState().trip?.id === tripId) {
        useUiStore.getState().setChatSending(false);
      }
    }
  }

  /**
   * Bounce the rider back to Acte 2 (full re-analysis) on a `change_route`
   * chat action. Mirrors {@link handleLaunchAnalysis} but is invoked from the
   * chat panel "Relancer l'analyse" button.
   */
  async function relaunchFullAnalysis(): Promise<boolean> {
    return handleLaunchAnalysis();
  }

  const [isShareModalOpen, setShareModalOpen] = useState(false);

  function handleShareTrip(): void {
    if (!tripId || !trip) return;
    setShareModalOpen(true);
  }

  function handleAddAccommodation(stageIndex: number) {
    const stage = stages[stageIndex];
    const accIndex = stage?.accommodations.length ?? 0;
    const newAcc: AccommodationData = {
      name: t("accommodation.new"),
      type: "other",
      lat: 0,
      lon: 0,
      estimatedPriceMin: 0,
      estimatedPriceMax: 0,
      isExactPrice: false,
      possibleClosed: false,
      distanceToEndPoint: 0,
      source: "osm",
    };
    actions.addLocalAccommodation(stageIndex, newAcc);
    setNewAccKey(`${stageIndex}-${accIndex}`);
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
    handleAiGeneration,
    handleDatesChange,
    handleDeleteStage,
    handleAddStage,
    handleDistanceChange,
    handlePacingChange,
    handlePacingCommit,
    handleEbikeModeChange,
    handleDepartureHourChange,
    handleAddAccommodation,
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
    sendChatMessage,
    relaunchFullAnalysis,
  };
}
