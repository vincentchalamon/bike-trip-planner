"use client";

import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { MagicLinkInput } from "@/components/magic-link-input";
import { TripSummary } from "@/components/trip-summary";
import { TripHeader } from "@/components/trip-header";
import { PacingSettings } from "@/components/pacing-settings";
import { Timeline } from "@/components/timeline";
import { ThemeToggle } from "@/components/theme-toggle";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { useMercure } from "@/hooks/use-mercure";
import { apiClient, parseApiError, isNetworkError } from "@/lib/api/client";
import { getRandomTripName } from "@/components/trip-title";
import type { AccommodationData, StageData } from "@/lib/validation/schemas";

export function TripPlanner() {
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
  const deleteStage = useTripStore((s) => s.deleteStage);
  const fatigueFactor = useTripStore((s) => s.fatigueFactor);
  const elevationPenalty = useTripStore((s) => s.elevationPenalty);
  const updatePacingSettings = useTripStore((s) => s.updatePacingSettings);
  const isProcessing = useUiStore((s) => s.isProcessing);
  const setProcessing = useUiStore((s) => s.setProcessing);

  const [newAccKey, setNewAccKey] = useState<string | null>(null);

  const tripId = trip?.id ?? null;
  useMercure(tripId);

  // --- Trip creation ---
  async function handleMagicLink(sourceUrl: string) {
    clearTrip();
    setProcessing(true);

    try {
      const today = new Date().toISOString().split("T")[0] ?? null;
      const { data, error, response } = await apiClient.POST("/trips", {
        body: {
          sourceUrl,
          fatigueFactor,
          elevationPenalty,
          startDate: startDate ?? today,
        },
      });

      if (error || !data) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
        setProcessing(false);
        return;
      }

      if (!startDate && today) {
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

  // --- Dates change ---
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

  // --- Stage operations ---
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

    // Optimistically insert a placeholder stage
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
      gpxContent: null,
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
        // Revert on error
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

  // --- Pacing settings ---
  async function handlePacingChange(newFatigue: number, newElevation: number) {
    updatePacingSettings(newFatigue, newElevation);
    if (!tripId) return;

    try {
      const { error, response } = await apiClient.PATCH("/trips/{id}", {
        params: { path: { id: tripId } },
        headers: { "Content-Type": "application/merge-patch+json" },
        body: {
          fatigueFactor: newFatigue,
          elevationPenalty: newElevation,
        },
      });

      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
      } else {
        setProcessing(true);
        useTripStore.getState().setStages([]);
      }
    } catch {
      toast.error(t("errors.failedUpdatePacing"));
    }
  }

  // --- Accommodations ---
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
    };
    addLocalAccommodation(stageIndex, newAcc);
    setNewAccKey(`${stageIndex}-${accIndex}`);
  }

  // --- Derived data ---
  const firstStage = stages[0];
  const firstWeather = firstStage?.weather ?? null;
  const isWeatherLoading = isProcessing && stages.length > 0 && !firstWeather;

  return (
    <main className="max-w-[1200px] mx-auto px-4 md:px-6 py-8 md:py-12 relative">
      {/* Skip link */}
      <a
        href="#timeline"
        className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-background focus:p-2 focus:rounded"
      >
        {t("layout.skipToTimeline")}
      </a>

      {/* Toolbar: magic link + buttons */}
      <div className="flex items-center gap-2">
        <div className="flex-1 min-w-0">
          <MagicLinkInput
            onSubmit={handleMagicLink}
            isProcessing={isProcessing}
            disabled={false}
          />
        </div>
        <ThemeToggle />
      </div>

      {/* Trip content */}
      {trip && (
        <div className="mt-8 space-y-8">
          {/* Summary */}
          <TripSummary
            totalDistance={totalDistance}
            totalElevation={totalElevation}
            totalElevationLoss={totalElevationLoss}
            weather={firstWeather}
            isWeatherLoading={isWeatherLoading}
            isProcessing={isProcessing}
          />

          {/* Header: title + locations + calendar + pacing */}
          <TripHeader
            title={trip.title}
            onTitleChange={updateTitle}
            startDate={startDate}
            endDate={endDate}
            onDatesChange={handleDatesChange}
            showTitleSuggestion={totalDistance !== null}
            isTitleLoading={isProcessing && totalDistance === null}
          >
            <PacingSettings
              fatigueFactor={fatigueFactor}
              elevationPenalty={elevationPenalty}
              onUpdate={handlePacingChange}
            />
          </TripHeader>

          {/* Timeline */}
          <div id="timeline">
            <Timeline
              stages={stages}
              startDate={startDate}
              isProcessing={isProcessing}
              onDeleteStage={handleDeleteStage}
              onAddStage={handleAddStage}
              onDistanceChange={handleDistanceChange}
              onAddAccommodation={handleAddAccommodation}
              onUpdateAccommodation={updateLocalAccommodation}
              onRemoveAccommodation={removeLocalAccommodation}
              newAccKey={newAccKey}
              onClearNewAcc={() => setNewAccKey(null)}
            />
          </div>
        </div>
      )}
    </main>
  );
}
