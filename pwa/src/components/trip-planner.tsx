"use client";

import { toast } from "sonner";
import { MagicLinkInput } from "@/components/magic-link-input";
import { TripSummary } from "@/components/trip-summary";
import { TripHeader } from "@/components/trip-header";
import { Timeline } from "@/components/timeline";
import { ExportPdfButton } from "@/components/export-pdf-button";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { useMercure } from "@/hooks/use-mercure";
import { apiClient, parseApiError, isNetworkError } from "@/lib/api/client";
import { getRandomTripName } from "@/components/trip-title";
import type { AccommodationData } from "@/lib/validation/schemas";
import type { GeocodeResult } from "@/lib/geocode/client";

function haversineDistance(
  lat1: number,
  lon1: number,
  lat2: number,
  lon2: number,
): number {
  const R = 6371;
  const dLat = ((lat2 - lat1) * Math.PI) / 180;
  const dLon = ((lon2 - lon1) * Math.PI) / 180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((lat1 * Math.PI) / 180) *
      Math.cos((lat2 * Math.PI) / 180) *
      Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

export function TripPlanner() {
  const trip = useTripStore((s) => s.trip);
  const totalDistance = useTripStore((s) => s.totalDistance);
  const totalElevation = useTripStore((s) => s.totalElevation);
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
  const updateStageLabel = useTripStore((s) => s.updateStageLabel);
  const isProcessing = useUiStore((s) => s.isProcessing);
  const setProcessing = useUiStore((s) => s.setProcessing);

  const tripId = trip?.id ?? null;
  useMercure(tripId);

  // --- Trip creation ---
  async function handleMagicLink(sourceUrl: string) {
    clearTrip();
    setProcessing(true);

    try {
      const { data, error, response } = await apiClient.POST("/trips", {
        body: { sourceUrl, fatigueFactor: 0.9, elevationPenalty: 50 },
      });

      if (error || !data) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
        setProcessing(false);
        return;
      }

      setTrip({
        id: data.id ?? "",
        title: getRandomTripName(),
        sourceUrl,
      });
    } catch (err) {
      if (isNetworkError(err)) {
        toast.error("Network error. Please check your connection.");
      } else {
        toast.error("An unexpected error occurred.");
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
          fatigueFactor: 0.9,
          elevationPenalty: 50,
        },
      });

      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error("Failed to update dates.");
    }
  }

  // --- Stage operations ---
  async function handleDeleteStage(index: number) {
    if (!tripId) return;
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
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error("Failed to delete stage.");
    }
  }

  async function handleMoveStage(index: number, direction: "up" | "down") {
    if (!tripId) return;
    const toIndex = direction === "up" ? index - 1 : index + 1;
    try {
      const { error, response } = await apiClient.PATCH(
        "/trips/{tripId}/stages/{index}/move",
        {
          params: { path: { tripId, index: String(index) } },
          headers: { "Content-Type": "application/merge-patch+json" },
          body: { toIndex },
        },
      );
      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error("Failed to move stage.");
    }
  }

  async function handleAddStage(afterIndex: number) {
    if (!tripId) return;
    try {
      const { error, response } = await apiClient.POST(
        "/trips/{tripId}/stages",
        {
          params: { path: { tripId } },
          body: { position: afterIndex + 1 },
        },
      );
      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error("Failed to add stage.");
    }
  }

  async function handleStageLocationChange(
    index: number,
    field: "start" | "end",
    result: GeocodeResult,
  ) {
    if (!tripId) return;

    updateStageLabel(
      index,
      field === "start" ? "startLabel" : "endLabel",
      result.name,
    );

    const body =
      field === "start"
        ? { startPoint: { lat: result.lat, lon: result.lon, ele: 0 } }
        : { endPoint: { lat: result.lat, lon: result.lon, ele: 0 } };

    try {
      const { error, response } = await apiClient.PATCH(
        "/trips/{tripId}/stages/{index}",
        {
          params: { path: { tripId, index: String(index) } },
          headers: { "Content-Type": "application/merge-patch+json" },
          body,
        },
      );
      if (error) {
        const apiError = parseApiError(response.status, error);
        toast.error(apiError.message);
      } else {
        setProcessing(true);
      }
    } catch {
      toast.error("Failed to update stage location.");
    }
  }

  // --- Accommodations ---
  function handleAddAccommodation(stageIndex: number) {
    const newAcc: AccommodationData = {
      name: "New accommodation",
      type: "other",
      lat: 0,
      lon: 0,
      estimatedPriceMin: 0,
      estimatedPriceMax: 0,
      isExactPrice: false,
    };
    addLocalAccommodation(stageIndex, newAcc);
  }

  // --- Derived data ---
  const firstStage = stages[0];
  const lastStage = stages[stages.length - 1];
  const departureLabel = firstStage?.startLabel ?? "";
  const arrivalLabel = lastStage?.endLabel ?? "";
  const isLoop =
    firstStage && lastStage
      ? haversineDistance(
          firstStage.startPoint.lat,
          firstStage.startPoint.lon,
          lastStage.endPoint.lat,
          lastStage.endPoint.lon,
        ) < 1
      : false;
  const firstWeather = firstStage?.weather ?? null;

  return (
    <main className="max-w-[1200px] mx-auto px-4 md:px-6 py-8 md:py-12">
      {/* Skip link */}
      <a
        href="#timeline"
        className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-background focus:p-2 focus:rounded"
      >
        Skip to timeline
      </a>

      {/* Magic link input */}
      <MagicLinkInput
        onSubmit={handleMagicLink}
        isProcessing={isProcessing}
        disabled={false}
      />

      {/* Trip content */}
      {trip && (
        <div className="mt-8 space-y-8">
          {/* Summary */}
          <TripSummary
            totalDistance={totalDistance}
            totalElevation={totalElevation}
          />

          {/* Header: title + locations + calendar */}
          <TripHeader
            title={trip.title}
            onTitleChange={updateTitle}
            departureLabel={departureLabel}
            arrivalLabel={arrivalLabel}
            isLoop={isLoop}
            weather={firstWeather}
            startDate={startDate}
            endDate={endDate}
            onDatesChange={handleDatesChange}
            onDepartureChange={() => {}}
            onArrivalChange={() => {}}
          />

          {/* Timeline */}
          <div id="timeline">
            <Timeline
              stages={stages}
              onDeleteStage={handleDeleteStage}
              onMoveStage={handleMoveStage}
              onAddStage={handleAddStage}
              onStageStartChange={(i, r) =>
                handleStageLocationChange(i, "start", r)
              }
              onStageEndChange={(i, r) =>
                handleStageLocationChange(i, "end", r)
              }
              onAddAccommodation={handleAddAccommodation}
              onUpdateAccommodation={updateLocalAccommodation}
              onRemoveAccommodation={removeLocalAccommodation}
            />
          </div>

          {/* Export PDF */}
          <ExportPdfButton />
        </div>
      )}
    </main>
  );
}
