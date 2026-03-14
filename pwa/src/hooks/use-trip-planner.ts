"use client";

import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { useMercure } from "@/hooks/use-mercure";
import {
  apiClient,
  parseApiError,
  isNetworkError,
  uploadGpxFile,
  scanAccommodations,
} from "@/lib/api/client";
import { getRandomTripName } from "@/lib/trip-utils";
import {
  MAX_ACCOMMODATION_RADIUS_KM,
  ACCOMMODATION_RADIUS_STEP_KM,
  DEFAULT_ACCOMMODATION_RADIUS_KM,
} from "@/lib/accommodation-constants";
import type { AccommodationData, StageData } from "@/lib/validation/schemas";

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
  const fatigueFactor = useTripStore((s) => s.fatigueFactor);
  const elevationPenalty = useTripStore((s) => s.elevationPenalty);
  const ebikeMode = useTripStore((s) => s.ebikeMode);
  const departureHour = useTripStore((s) => s.departureHour);
  const updatePacingSettings = useTripStore((s) => s.updatePacingSettings);
  const setEbikeMode = useTripStore((s) => s.setEbikeMode);
  const updateStageAlerts = useTripStore((s) => s.updateStageAlerts);
  const isProcessing = useUiStore((s) => s.isProcessing);
  const setProcessing = useUiStore((s) => s.setProcessing);

  const [newAccKey, setNewAccKey] = useState<string | null>(null);

  const tripId = trip?.id ?? null;
  useMercure(tripId);

  async function handleMagicLink(sourceUrl: string) {
    clearTrip();
    setProcessing(true);

    try {
      const today = new Date().toISOString().split("T")[0]!;
      const { data, error, response } = await apiClient.POST("/trips", {
        body: {
          sourceUrl,
          fatigueFactor,
          elevationPenalty,
          ebikeMode,
          departureHour,
          startDate: startDate ?? today,
        },
      });

      if (error || !data) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
        setProcessing(false);
        return;
      }

      if (!startDate) {
        updateDates(today, null);
      }

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
    }
  }

  async function handleGpxUpload(file: File) {
    clearTrip();
    setProcessing(true);

    try {
      const today = new Date().toISOString().split("T")[0]!;
      const { data, error } = await uploadGpxFile(file, {
        fatigueFactor,
        elevationPenalty,
        ebikeMode,
        startDate: startDate ?? today,
      });

      if (error || !data) {
        toast.error(t("errors.gpxUploadFailed"));
        setProcessing(false);
        return;
      }

      if (!startDate) {
        updateDates(today, null);
      }

      setTrip({
        id: data.id,
        title: data.title ?? file.name.replace(/\.gpx$/i, ""),
        sourceUrl: "",
      });
    } catch (err) {
      if (isNetworkError(err)) {
        toast.error(t("errors.networkError"));
      } else {
        toast.error(t("errors.unexpectedError"));
      }
      setProcessing(false);
    }
  }

  async function handleDatesChange(
    newStart: string | null,
    newEnd: string | null,
  ) {
    updateDates(newStart, newEnd);
    if (!tripId) return;

    try {
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          startDate: newStart,
          endDate: newEnd,
          fatigueFactor,
          elevationPenalty,
          ebikeMode,
          departureHour,
        },
      });

      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error(t("errors.failedUpdateDates"));
    }
  }

  async function handleDeleteStage(index: number) {
    if (!tripId) return;

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
        useTripStore.getState().setStages(snapshot);
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error(t("errors.failedDeleteStage"));
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
        useTripStore.getState().setStages(snapshot);
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error(t("errors.failedInsertRestDay"));
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
      isRestDay: false,
    };
    const updatedStages = stages.map((s) => ({ ...s }));
    updatedStages.splice(afterIndex + 1, 0, placeholder);
    updatedStages.forEach((s, i) => {
      s.dayNumber = i + 1;
    });
    useTripStore.getState().setStages(updatedStages);

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
        useTripStore.getState().setStages(stages);
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error(t("errors.failedAddStage"));
      useTripStore.getState().setStages(stages);
    }
  }

  async function handleDistanceChange(index: number, distance: number) {
    if (!tripId) return;

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
        setProcessing(true);
      }
    } catch {
      toast.error(t("errors.failedUpdateLocation"));
    }
  }

  async function patchPacingSettings(
    newFatigue: number,
    newElevation: number,
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
          ebikeMode: newEbikeMode,
          departureHour,
        },
      });

      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
      } else {
        setProcessing(true);
        if (clearStages) {
          useTripStore.getState().setStages([]);
        }
      }
    } catch {
      toast.error(t("errors.failedUpdatePacing"));
    }
  }

  async function handlePacingChange(newFatigue: number, newElevation: number) {
    updatePacingSettings(newFatigue, newElevation);
    await patchPacingSettings(newFatigue, newElevation, ebikeMode, true);
  }

  async function handleEbikeModeChange(newEbikeMode: boolean) {
    setEbikeMode(newEbikeMode);
    if (!newEbikeMode) {
      stages.forEach((_, i) => updateStageAlerts(i, [], "terrain"));
    }
    await patchPacingSettings(
      fatigueFactor,
      elevationPenalty,
      newEbikeMode,
      false,
    );
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
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
        // Rollback on error: restore accommodations from store snapshot
        useTripStore.getState().setStages([...stages]);
      } else {
        setProcessing(true);
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
    ebikeMode,
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
    handleEbikeModeChange,
    handleAddAccommodation,
    handleSelectAccommodation,
    handleDeselectAccommodation,
    handleExpandAccommodationRadius,
    handleInsertRestDay,
    clearNewAccKey: () => setNewAccKey(null),
  };
}
