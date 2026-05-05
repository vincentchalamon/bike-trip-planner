"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { useParams } from "next/navigation";
import { useRouter } from "next/navigation";
import { Suspense } from "react";
import { Loader2 } from "lucide-react";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import { TripNotFound } from "@/components/trip-not-found";
import { TripSummarySkeleton } from "@/components/trip-summary-skeleton";
import { TimelineSidebarSkeleton } from "@/components/timeline-sidebar-skeleton";
import { StagePanelSkeleton } from "@/components/stage-panel-skeleton";
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
          // A persisted trip with stages has already gone through Phase 2,
          // so bypass the Acte 1.5 preview gate and render the full view.
          useUiStore.getState().setAnalysisStarted(true);
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
    return <TripNotFound />;
  }

  if (!isLoaded) {
    return (
      <main
        className="max-w-[1200px] mx-auto px-4 md:px-6 py-8 md:py-12 space-y-6"
        data-testid="trip-loading-skeleton"
        aria-busy="true"
      >
        <TripSummarySkeleton />
        <div className="flex items-center justify-center gap-3 text-muted-foreground text-sm">
          <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          <span>{t("loading")}</span>
        </div>
        <div className="flex flex-col gap-6 lg:flex-row lg:gap-8 lg:items-start">
          <aside className="w-full lg:w-[260px] lg:shrink-0 rounded-xl border border-border bg-card/40 p-3 lg:p-4">
            <TimelineSidebarSkeleton count={4} />
          </aside>
          <div className="flex-1 min-w-0">
            <StagePanelSkeleton />
          </div>
        </div>
      </main>
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
