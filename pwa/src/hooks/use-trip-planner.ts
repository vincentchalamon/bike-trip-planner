"use client";

import { useRef, useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
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
} from "@/lib/api/client";
import { getRandomTripName } from "@/lib/trip-utils";
import {
  MAX_ACCOMMODATION_RADIUS_KM,
  ACCOMMODATION_RADIUS_STEP_KM,
  DEFAULT_ACCOMMODATION_RADIUS_KM,
} from "@/lib/accommodation-constants";
import type { AccommodationData, StageData } from "@/lib/validation/schemas";
import type { AccommodationType } from "@/lib/accommodation-types";

export function useTripPlanner() {
  const t = useTranslations();
  const trip = useTripStore((s) => s.trip);
  const totalDistance = useTripStore((s) => s.totalDistance);
  const totalElevation = useTripStore((s) => s.totalElevation);
  const totalElevationLoss = useTripStore((s) => s.totalElevationLoss);
  const stages = useTripStore((s) => s.stages);
  const startDate = useTripStore((s) => s.startDate);
  const endDate = useTripStore((s) => s.endDate);
  const setTrip = useTripStore((s) => s.setTrip);
  const updateRouteData = useTripStore((s) => s.updateRouteData);
  const updateTitle = useTripStore((s) => s.updateTitle);
  const updateDates = useTripStore((s) => s.updateDates);
  const clearTrip = useTripStore((s) => s.clearTrip);
  const addLocalAccommodation = useTripStore((s) => s.addLocalAccommodation);
  const removeLocalAccommodation = useTripStore(
    (s) => s.removeLocalAccommodation,
  );
  const updateLocalAccommodation = useTripStore(
    (s) => s.updateLocalAccommodation,
  );
  const updateStageAccommodations = useTripStore(
    (s) => s.updateStageAccommodations,
  );
  const selectAccommodationInStore = useTripStore((s) => s.selectAccommodation);
  const deselectAccommodationInStore = useTripStore(
    (s) => s.deselectAccommodation,
  );
  const deleteStage = useTripStore((s) => s.deleteStage);
  const insertRestDay = useTripStore((s) => s.insertRestDay);
  const insertStagePlaceholder = useTripStore((s) => s.insertStagePlaceholder);
  const fatigueFactor = useTripStore((s) => s.fatigueFactor);
  const elevationPenalty = useTripStore((s) => s.elevationPenalty);
  const maxDistancePerDay = useTripStore((s) => s.maxDistancePerDay);
  const averageSpeed = useTripStore((s) => s.averageSpeed);
  const ebikeMode = useTripStore((s) => s.ebikeMode);
  const departureHour = useTripStore((s) => s.departureHour);
  const setDepartureHour = useTripStore((s) => s.setDepartureHour);
  const enabledAccommodationTypes = useTripStore(
    (s) => s.enabledAccommodationTypes,
  );
  const updatePacingSettingsInternal = useTripStore(
    (s) => s.updatePacingSettingsInternal,
  );
  const updateDatesInternal = useTripStore((s) => s.updateDatesInternal);
  const setEbikeMode = useTripStore((s) => s.setEbikeMode);
  const setEnabledAccommodationTypes = useTripStore(
    (s) => s.setEnabledAccommodationTypes,
  );
  const updateStageAlerts = useTripStore((s) => s.updateStageAlerts);
  const isLocked = useTripStore((s) => s.isLocked);
  const setIsLocked = useTripStore((s) => s.setIsLocked);
  const isProcessing = useUiStore((s) => s.isProcessing);
  const setProcessing = useUiStore((s) => s.setProcessing);
  const setAccommodationScanning = useUiStore(
    (s) => s.setAccommodationScanning,
  );

  const [newAccKey, setNewAccKey] = useState<string | null>(null);
  const preDragPacingSnapshot = useRef<ReturnType<
    typeof getUndoableSlice
  > | null>(null);

  const tripId = trip?.id ?? null;
  useMercure(tripId);

  async function handleMagicLink(sourceUrl: string) {
    clearTrip();
    setProcessing(true);
    setAccommodationScanning(true);

    try {
      const today = new Date().toISOString().split("T")[0]!;
      const { data, error, response } = await apiClient.POST("/trips", {
        body: {
          sourceUrl,
          fatigueFactor,
          elevationPenalty,
          maxDistancePerDay,
          averageSpeed,
          ebikeMode,
          departureHour,
          startDate: startDate ?? today,
          enabledAccommodationTypes,
        },
      });

      if (error || !data) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
        setProcessing(false);
        setAccommodationScanning(false);
        return;
      }

      if (!startDate) {
        updateDatesInternal(today, null);
      }

      setIsLocked(data.isLocked === true);
      setTrip({
        id: data.id ?? "",
        title: getRandomTripName(),
        sourceUrl,
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

  async function handleGpxUpload(file: File) {
    clearTrip();
    setProcessing(true);
    setAccommodationScanning(true);

    try {
      const today = new Date().toISOString().split("T")[0]!;
      const { data, error } = await uploadGpxFile(file, {
        fatigueFactor,
        elevationPenalty,
        maxDistancePerDay,
        averageSpeed,
        ebikeMode,
        startDate: startDate ?? today,
        enabledAccommodationTypes,
      });

      if (error || !data) {
        toast.error(t("errors.gpxUploadFailed"));
        setProcessing(false);
        setAccommodationScanning(false);
        return;
      }

      if (!startDate) {
        updateDatesInternal(today, null);
      }

      setTrip({
        id: data.id,
        title: data.title ?? file.name.replace(/\.gpx$/i, ""),
        sourceUrl: "",
      });

      updateRouteData({
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
    updateDates(newStart, newEnd);
    if (!tripId) return;

    try {
      const { data, error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          startDate: newStart,
          endDate: newEnd,
          fatigueFactor,
          elevationPenalty,
          maxDistancePerDay,
          averageSpeed,
          ebikeMode,
          departureHour,
          enabledAccommodationTypes,
        },
      });

      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
      } else {
        if (data) setIsLocked(data.isLocked === true);
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedUpdateDates"));
    }
  }

  async function handleDeleteStage(index: number) {
    if (!tripId) return;

    const isRestDay = stages[index]?.isRestDay ?? false;
    const snapshot = [...stages];
    deleteStage(index);

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

    const snapshot = [...stages];
    insertRestDay(afterIndex);

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

    const prevStage = stages[afterIndex];
    const nextStage = stages[afterIndex + 1];
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
      isRestDay: false,
    };
    // insertStagePlaceholder pushes an undo snapshot internally before mutating.
    insertStagePlaceholder(afterIndex, placeholder);

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
        useTripStore.getState().setStages(stages);
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedAddStage"));
      useTripTemporalStore.getState()._pop();
      useTripStore.getState().setStages(stages);
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
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          fatigueFactor: newFatigue,
          elevationPenalty: newElevation,
          maxDistancePerDay: newMaxDistance,
          averageSpeed: newAverageSpeed,
          ebikeMode: newEbikeMode,
          departureHour,
          enabledAccommodationTypes,
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
    updatePacingSettingsInternal(
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
    updatePacingSettingsInternal(
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
      ebikeMode,
      true,
    );
  }

  async function handleDepartureHourChange(newDepartureHour: number) {
    setDepartureHour(newDepartureHour);
    if (!tripId) return;

    try {
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          fatigueFactor,
          elevationPenalty,
          maxDistancePerDay,
          averageSpeed,
          ebikeMode,
          departureHour: newDepartureHour,
          enabledAccommodationTypes,
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
    setEbikeMode(newEbikeMode);
    if (!newEbikeMode) {
      stages.forEach((_, i) => updateStageAlerts(i, [], "terrain"));
    }
    await patchPacingSettings(
      fatigueFactor,
      elevationPenalty,
      maxDistancePerDay,
      averageSpeed,
      newEbikeMode,
      false,
    );
  }

  async function handleAccommodationTypesChange(newTypes: AccommodationType[]) {
    const previous = enabledAccommodationTypes;
    setEnabledAccommodationTypes(newTypes);
    if (!tripId) return;

    try {
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          fatigueFactor,
          elevationPenalty,
          maxDistancePerDay,
          averageSpeed,
          ebikeMode,
          departureHour,
          enabledAccommodationTypes: newTypes,
        },
      });

      if (error) {
        setEnabledAccommodationTypes(previous);
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      setEnabledAccommodationTypes(previous);
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
    };
    addLocalAccommodation(stageIndex, newAcc);
    setNewAccKey(`${stageIndex}-${accIndex}`);
  }

  async function handleSelectAccommodation(
    stageIndex: number,
    accIndex: number,
  ) {
    if (!tripId) return;

    const acc = stages[stageIndex]?.accommodations[accIndex];
    if (!acc) return;

    const nextStageIndex =
      stageIndex + 1 < stages.length ? stageIndex + 1 : null;

    // Optimistic update
    selectAccommodationInStore(stageIndex, accIndex, nextStageIndex);

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
          useTripStore.getState().setStages([...stages]);
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
          useTripStore.getState().setStages([...stages]);
        }
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedSelectAccommodation"));
      useTripStore.getState().setStages([...stages]);
    }
  }

  async function handleDeselectAccommodation(stageIndex: number) {
    if (!tripId) return;

    // Optimistic update
    deselectAccommodationInStore(stageIndex);

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
        useTripStore.getState().setStages([...stages]);
      } else {
        setProcessing(true);
        setAccommodationScanning(true);
      }
    } catch {
      toast.error(t("errors.failedDeselectAccommodation"));
      useTripStore.getState().setStages([...stages]);
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
    updateTitle,
    updateLocalAccommodation,
    removeLocalAccommodation,
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
    clearNewAccKey: () => setNewAccKey(null),
  };
}
