"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useRouter } from "next/navigation";
import { Suspense } from "react";
import { ArrowLeft, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { apiFetch } from "@/lib/api/client";
import { API_URL } from "@/lib/constants";
import type { StageData } from "@/lib/validation/schemas";
import type { AccommodationType } from "@/lib/accommodation-types";
import type { components } from "@/lib/api/schema";

type TripDetailResponse = components["schemas"]["TripDetail.jsonld"];

function TripLoader({ tripId }: { tripId: string }) {
  const t = useTranslations("tripList");
  const router = useRouter();
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
  const setIsLocked = useTripStore((s) => s.setIsLocked);
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
          data.startDate?.split("T")[0] ?? null,
          data.endDate?.split("T")[0] ?? null,
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
        setIsLocked(data.isLocked === true);

        // Convert stages to Zustand StageData shape
        const stages: StageData[] = (data.stages ?? []).map((s) => {
          return {
            dayNumber: s.dayNumber ?? 0,
            distance: s.distance ?? 0,
            elevation: s.elevation ?? 0,
            elevationLoss: s.elevationLoss ?? 0,
            startPoint: (s.startPoint as StageData["startPoint"]) ?? {
              lat: 0,
              lon: 0,
              ele: 0,
            },
            endPoint: (s.endPoint as StageData["endPoint"]) ?? {
              lat: 0,
              lon: 0,
              ele: 0,
            },
            geometry: (s.geometry as StageData["geometry"]) ?? [],
            label: s.label ?? null,
            startLabel: null,
            endLabel: null,
            weather: (s.weather as StageData["weather"]) ?? null,
            alerts: (s.alerts as StageData["alerts"]) ?? [],
            pois: (s.pois as StageData["pois"]) ?? [],
            accommodations:
              (s.accommodations as StageData["accommodations"]) ?? [],
            selectedAccommodation:
              (s.selectedAccommodation as StageData["selectedAccommodation"]) ??
              null,
            accommodationSearchRadiusKm: 5,
            isRestDay: s.isRestDay ?? false,
            supplyTimeline: [],
            events: [],
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
    setIsLocked,
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
    <TripPlannerErrorBoundary>
      <Suspense fallback={null}>
        <TripPlanner
          onClose={() => {
            clearTrip();
            useUiStore.getState().setProcessing(false);
            useUiStore.getState().setAccommodationScanning(false);
            router.push("/");
          }}
        />
      </Suspense>
    </TripPlannerErrorBoundary>
  );
}

export default function TripDetailPage() {
  const { id } = useParams<{ id: string }>();

  return (
    <HydrationBoundary>
      <TripLoader tripId={id} />
    </HydrationBoundary>
  );
}
