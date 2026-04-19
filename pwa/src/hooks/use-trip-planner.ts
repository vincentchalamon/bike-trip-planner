"use client";

import { useRef, useState } from "react";
import { toast } from "sonner";
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
  isNetworkError,
  uploadGpxFile,
  scanAccommodations,
  addPoiWaypointToRoute,
  duplicateTrip,
} from "@/lib/api/client";
import { getRandomTripName } from "@/lib/trip-utils";
import {
  MAX_ACCOMMODATION_RADIUS_KM,
  ACCOMMODATION_RADIUS_STEP_KM,
  DEFAULT_ACCOMMODATION_RADIUS_KM,
} from "@/lib/accommodation-constants";
import type { AccommodationData, StageData } from "@/lib/validation/schemas";
import type { AccommodationType } from "@/lib/accommodation-types";

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
    })),
  );

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

  const tripId = trip?.id ?? null;
  useMercure(tripId, mercureToken);

  async function handleMagicLink(sourceUrl: string) {
    actions.clearTrip();
    setMercureToken(null);
    setProcessing(true);
    setAccommodationScanning(true);

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
        toast.error(apiError.message);
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
    setAccommodationScanning(true);

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

      actions.updateRouteData({
        totalDistance: data.totalDistance,
        totalElevation: data.totalElevation,
        totalElevationLoss: data.totalElevationLoss,
        sourceType: "gpx_upload",
        title: data.title ?? null,
      });
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
        toast.error(apiError.message);
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
        toast.error(apiError.message);
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
        toast.error(apiError.message);
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
        toast.error(apiError.message);
      } else {
        // Push snapshot only after a successful PATCH to avoid phantom undo entries
        useTripTemporalStore.getState()._push(snapshot);
        setProcessing(true);
        setAccommodationScanning(true);
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
    clearStages: boolean,
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
        toast.error(apiError.message);
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
        if (clearStages) {
          useTripStore.getState().setStages([]);
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
      true,
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
        toast.error(apiError.message);
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
    await patchPacingSettings(
      pacing.fatigueFactor,
      pacing.elevationPenalty,
      pacing.maxDistancePerDay,
      pacing.averageSpeed,
      newEbikeMode,
      false,
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
        toast.error(apiError.message);
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
        setAccommodationScanning(true);
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
        toast.error(apiError.message);
        useTripStore.getState().setStages([...currentStages]);
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedDeselectAccommodation"));
      useTripStore.getState().setStages([...currentStages]);
    }
  }

  const firstStage = stages[0];
  const firstWeather = firstStage?.weather ?? null;
  const isWeatherLoading = isProcessing && stages.length > 0 && !firstWeather;

  return {
    trip,
    isLocked,
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
    handleAddAccommodation,
    handleSelectAccommodation,
    handleDeselectAccommodation,
    handleExpandAccommodationRadius,
    handleInsertRestDay,
    handleAddPoiWaypoint,
    handleDuplicateTrip,
    handleShareTrip,
    isShareModalOpen,
    setShareModalOpen,
    clearNewAccKey: () => setNewAccKey(null),
  };
}
