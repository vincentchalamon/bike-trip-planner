"use client";

import { use, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import Link from "next/link";
import { Suspense } from "react";
import { ArrowLeft, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { useTripStore } from "@/store/trip-store";
import { apiFetch } from "@/lib/api/client";
import { API_URL } from "@/lib/constants";
import type { StageData } from "@/lib/validation/schemas";
import type { AccommodationType } from "@/lib/accommodation-types";
import type { components } from "@/lib/api/schema";

type TripDetailResponse = components["schemas"]["TripDetail.jsonld"];

function TripLoader({ tripId }: { tripId: string }) {
  const t = useTranslations("tripList");
  const [loadError, setLoadError] = useState(false);
  const [isLoaded, setIsLoaded] = useState(false);

  const setTrip = useTripStore((s) => s.setTrip);
  const setStages = useTripStore((s) => s.setStages);
  const updateDatesInternal = useTripStore((s) => s.updateDatesInternal);
  const updatePacingSettingsInternal = useTripStore(
    (s) => s.updatePacingSettingsInternal,
  );
  const setEbikeMode = useTripStore((s) => s.setEbikeMode);
  const setDepartureHour = useTripStore((s) => s.setDepartureHour);
  const setEnabledAccommodationTypes = useTripStore(
    (s) => s.setEnabledAccommodationTypes,
  );
  const clearTrip = useTripStore((s) => s.clearTrip);

  useEffect(() => {
    let cancelled = false;

    async function loadTrip() {
      try {
        const res = await apiFetch(
          `${API_URL}/trips/${encodeURIComponent(tripId)}/detail`,
          { headers: { Accept: "application/ld+json" } },
        );

        if (!res.ok || cancelled) {
          if (!cancelled) setLoadError(true);
          return;
        }

        const data = (await res.json()) as TripDetailResponse;

        if (cancelled) return;

        // Hydrate the Zustand store with persisted data
        setTrip({
          id: data.id ?? "",
          title: data.title ?? "",
          sourceUrl: data.sourceUrl ?? "",
        });

        updateDatesInternal(
          data.startDate
            ? (new Date(data.startDate).toISOString().split("T")[0] ?? null)
            : null,
          data.endDate
            ? (new Date(data.endDate).toISOString().split("T")[0] ?? null)
            : null,
        );

        updatePacingSettingsInternal(
          data.fatigueFactor ?? 0.9,
          data.elevationPenalty ?? 50,
          data.maxDistancePerDay ?? 80,
          data.averageSpeed ?? 15,
        );

        setEbikeMode(data.ebikeMode ?? false);
        setDepartureHour(data.departureHour ?? 8);
        setEnabledAccommodationTypes(
          (data.enabledAccommodationTypes ?? []) as AccommodationType[],
        );

        // Convert stages to Zustand StageData shape
        const stages: StageData[] = (data.stages ?? []).map((s) => {
          const stage = s as Record<string, unknown>;
          return {
            dayNumber: (stage.dayNumber as number) ?? 0,
            distance: (stage.distance as number) ?? 0,
            elevation: (stage.elevation as number) ?? 0,
            elevationLoss: (stage.elevationLoss as number) ?? 0,
            startPoint: (stage.startPoint as StageData["startPoint"]) ?? {
              lat: 0,
              lon: 0,
              ele: 0,
            },
            endPoint: (stage.endPoint as StageData["endPoint"]) ?? {
              lat: 0,
              lon: 0,
              ele: 0,
            },
            geometry: (stage.geometry as StageData["geometry"]) ?? [],
            label: (stage.label as string | null) ?? null,
            startLabel: null,
            endLabel: null,
            weather: (stage.weather as StageData["weather"]) ?? null,
            alerts: (stage.alerts as StageData["alerts"]) ?? [],
            pois: (stage.pois as StageData["pois"]) ?? [],
            accommodations:
              (stage.accommodations as StageData["accommodations"]) ?? [],
            selectedAccommodation:
              (stage.selectedAccommodation as StageData["selectedAccommodation"]) ??
              null,
            accommodationSearchRadiusKm: 5,
            isRestDay: (stage.isRestDay as boolean) ?? false,
            supplyTimeline: [],
          };
        });

        setStages(stages);

        if (stages.length > 0) {
          const totalDistance = stages
            .filter((s) => !s.isRestDay)
            .reduce((sum, s) => sum + s.distance, 0);
          const totalElevation = stages
            .filter((s) => !s.isRestDay)
            .reduce((sum, s) => sum + s.elevation, 0);

          useTripStore.getState().updateRouteData({
            totalDistance,
            totalElevation,
            totalElevationLoss: stages.reduce(
              (sum, s) => sum + (s.elevationLoss ?? 0),
              0,
            ),
            sourceType: "persisted",
            title: data.title ?? null,
          });
        }

        setIsLoaded(true);
      } catch {
        if (!cancelled) setLoadError(true);
      }
    }

    void loadTrip();

    return () => {
      cancelled = true;
      clearTrip();
    };
  }, [
    tripId,
    setTrip,
    setStages,
    updateDatesInternal,
    updatePacingSettingsInternal,
    setEbikeMode,
    setDepartureHour,
    setEnabledAccommodationTypes,
    clearTrip,
  ]);

  if (loadError) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[60vh] gap-4">
        <p className="text-destructive">{t("loadingError")}</p>
        <Button asChild variant="outline">
          <Link href="/trips">
            <ArrowLeft className="h-4 w-4 mr-2" />
            {t("backToList")}
          </Link>
        </Button>
      </div>
    );
  }

  if (!isLoaded) {
    return (
      <div className="flex items-center justify-center min-h-[60vh] gap-3 text-muted-foreground">
        <Loader2 className="h-5 w-5 animate-spin" />
        <span>{t("loading")}</span>
      </div>
    );
  }

  return (
    <>
      {/* Back button */}
      <div className="max-w-[1200px] mx-auto px-4 md:px-6 pt-4">
        <Button asChild variant="ghost" size="sm">
          <Link href="/trips">
            <ArrowLeft className="h-4 w-4 mr-2" />
            {t("backToList")}
          </Link>
        </Button>
      </div>
      <TripPlannerErrorBoundary>
        <Suspense fallback={null}>
          <TripPlanner />
        </Suspense>
      </TripPlannerErrorBoundary>
    </>
  );
}

export default function TripDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);

  return (
    <HydrationBoundary>
      <TripLoader tripId={id} />
    </HydrationBoundary>
  );
}
