"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { useParams } from "next/navigation";
import { Suspense } from "react";
import { Loader2 } from "lucide-react";
import { TripPlanner } from "@/components/trip-planner";
import { TripPlannerErrorBoundary } from "@/components/trip-planner-error-boundary";
import { TripNotFound } from "@/components/trip-not-found";
import { TripSummarySkeleton } from "@/components/trip-summary-skeleton";
import { StagePanelSkeleton } from "@/components/stage-panel-skeleton";
import { Skeleton } from "@/components/ui/skeleton";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { apiFetch } from "@/lib/api/client";
import { API_URL } from "@/lib/constants";
import { resolveStageLabels } from "@/hooks/use-mercure";
import type { StageData } from "@/lib/validation/schemas";
import type { AccommodationType } from "@/lib/accommodation-types";
import type { components } from "@/lib/api/schema";
import {
  INITIAL_RETRY_MS,
  MAX_RESYNC_MS,
  RESYNC_INTERVAL_MS,
  needsResync,
  shouldRetryInitialLoad,
} from "./trip-load";

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
  const setIsLocked = useTripStore((s) => s.setIsLocked);
  const setOutOfZone = useTripStore((s) => s.setOutOfZone);
  const clearTrip = useTripStore((s) => s.clearTrip);

  useEffect(() => {
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    // A 404 is only treated as transient (worth retrying) when this trip is the
    // one we just created: it's already in the store via setTrip() before the
    // post-creation router.push, so the row may simply not be readable yet. A
    // direct visit to a foreign / missing trip has an empty store, and its 404
    // (object-level authz is hidden as 404, ADR-038) must surface immediately.
    const ownsFreshTrip = useTripStore.getState().trip?.id === tripId;

    // Hydrate the Zustand store from a /detail payload.
    function hydrate(data: TripDetailResponse): void {
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
      setOutOfZone(data.outOfZone === true);

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
          onCycleNetwork: s.onCycleNetwork ?? 0,
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

        // The backend does not persist reverse-geocoded labels (they are a
        // client-side concern), and on a reload no Mercure event fires to fill
        // them — so the stage cards would fall back to raw GPS coordinates.
        // Resolve them here, after hydration, like the Mercure path does
        // (recette #649).
        void resolveStageLabels(
          stages,
          stages.map((_, i) => i),
        );
      }

      // Drive the synchronous-flow loader vs. trip view from `status`
      // (ADR-043, PR4-front). A `draft` trip has no structural stages yet:
      // keep the global processing flag on so the planner shows the single
      // loader until the trip becomes `ready`. A `ready` trip renders the full
      // view immediately.
      const ui = useUiStore.getState();
      ui.setProcessing(data.status !== "ready");

      // Hydrate the per-block async enrichment status from the detail payload so
      // the weather / AI spinners reflect server-side progress on reload (a
      // running block keeps spinning; a done/failed block renders its terminal
      // state). Mercure events keep these live afterwards.
      ui.setBlockStatus("weather", data.weatherStatus ?? null);
      ui.setBlockStatus("ai", data.aiStatus ?? null);
    }

    async function fetchDetail(): Promise<Response | null> {
      try {
        return await apiFetch(
          `${API_URL}/trips/${encodeURIComponent(tripId)}/detail`,
          { headers: { Accept: "application/ld+json" } },
        );
      } catch {
        return null;
      }
    }

    // Phase 2 — keep polling while the trip has no stages yet. Re-hydrate only
    // once stages are present so we never overwrite stages already pushed live
    // by Mercure with an empty draft payload; until then we just re-check.
    function scheduleResync(startedAt: number): void {
      if (cancelled) return;
      if (Date.now() - startedAt >= MAX_RESYNC_MS) {
        // Gave up waiting for the structural computation. The single loader is
        // driven by stages.length (not `processing`), so if nothing ever
        // rendered — no stages from /detail nor from a live Mercure event —
        // surface the error instead of an endless spinner. If stages did arrive
        // meanwhile, leave the rendered view alone.
        if (useTripStore.getState().stages.length === 0) setLoadError(true);
        return;
      }

      timer = setTimeout(() => {
        void (async () => {
          const res = await fetchDetail();
          if (cancelled) return;

          if (res?.ok) {
            try {
              const data = (await res.json()) as TripDetailResponse;
              if (cancelled) return;
              if (!needsResync(data)) {
                hydrate(data);
                return; // structural stages present — stop polling
              }
            } catch {
              // Malformed payload — treat as a transient blip and keep polling.
            }
          }

          scheduleResync(startedAt);
        })();
      }, RESYNC_INTERVAL_MS);
    }

    // Phase 1 — initial load with bounded retry on a transient failure.
    async function initialLoad(attempt: number): Promise<void> {
      const res = await fetchDetail();
      if (cancelled) return;

      if (!res?.ok) {
        if (shouldRetryInitialLoad(res, attempt, ownsFreshTrip)) {
          timer = setTimeout(
            () => void initialLoad(attempt + 1),
            INITIAL_RETRY_MS,
          );
        } else {
          setLoadError(true);
        }
        return;
      }

      let data: TripDetailResponse;
      try {
        data = (await res.json()) as TripDetailResponse;
      } catch {
        // A 200 with a non-JSON body (e.g. an HTML error page from a proxy)
        // would otherwise reject and hang the loader — surface the error.
        if (!cancelled) setLoadError(true);
        return;
      }
      if (cancelled) return;

      hydrate(data);
      setIsLoaded(true);

      if (needsResync(data)) scheduleResync(Date.now());
    }

    void initialLoad(0);

    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
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
    setOutOfZone,
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
        {/* Single-column roadbook skeleton — mirrors the new layout where the
            horizontal day timeline sits above the stage cards (recette #649). */}
        <div className="flex flex-col gap-6">
          <div className="rounded-xl border border-border bg-card/40 px-4 py-3">
            <Skeleton className="h-4 w-full" />
          </div>
          <StagePanelSkeleton />
        </div>
      </main>
    );
  }

  return (
    <TripPlannerErrorBoundary>
      <Suspense fallback={null}>
        <TripPlanner />
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
