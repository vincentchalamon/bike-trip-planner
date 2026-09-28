"use client";

import { useEffect, useMemo, useState, useCallback } from "react";
import { useTranslations } from "next-intl";
import { RoadbookMasterDetail } from "@/components/Timeline";
import { TripSummary } from "@/components/trip-summary";
import { MapPanel } from "@/components/Map/MapPanel";
import { ViewModeToggle } from "@/components/ViewModeToggle";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { SiteChrome } from "@/components/site-chrome";
import { SharedViewBanner } from "@/components/shared-view-banner";
import { TripDownloads } from "@/components/trip-downloads";
import { fetchSharedTripRoute, type SharedTripDetail } from "@/lib/api/client";
import { ShareProvider } from "@/lib/share-context";
import { useUiStore } from "@/store/ui-store";
import { useTripStore } from "@/store/trip-store";
import {
  computeEstimatedBudget,
  computeTripTotals,
  stageDataFromDetail,
  tripSettingsFromDetail,
} from "@btp/core";
import type { StageData } from "@btp/core";

function SharedTripLoader({
  code,
  trip,
}: {
  code: string;
  trip: SharedTripDetail;
}) {
  const t = useTranslations("sharePage");
  const viewMode = useUiStore((s) => s.viewMode);
  const setStagesInStore = useTripStore((s) => s.setStages);
  const clearTrip = useTripStore((s) => s.clearTrip);
  const [geometryByDay, setGeometryByDay] = useState<Map<
    number | undefined,
    StageData["geometry"]
  > | null>(null);
  const [focusedStageIndex, setFocusedStageIndex] = useState<number | null>(
    null,
  );

  const title = trip.title ?? null;
  // Server-persisted reverse-geocoded labels (recette #649 #3c) matter most
  // here: the anonymous view cannot call the auth-gated /geocode endpoint.
  const detailStages = useMemo(
    () => (trip.stages ?? []).map(stageDataFromDetail),
    [trip],
  );
  // The page renders these stages, not the store's, so the route geometry
  // (split off /detail, ADR-057) must be merged here too: the store's
  // applyRoute alone never reaches the map / elevation profile.
  const stages = useMemo(
    () =>
      geometryByDay
        ? detailStages.map((s) =>
            geometryByDay.has(s.dayNumber)
              ? { ...s, geometry: geometryByDay.get(s.dayNumber)! }
              : s,
          )
        : detailStages,
    [detailStages, geometryByDay],
  );
  const totals = useMemo(() => computeTripTotals(stages), [stages]);
  const settings = useMemo(() => tripSettingsFromDetail(trip), [trip]);

  useEffect(() => {
    let cancelled = false;

    // Hydrate the trip store so that <RoadbookMasterDetail /> (which reads
    // `selectedStageIndex` from the store) works correctly. The store stays
    // local: no `setTrip()` call means no API/PATCH calls can be issued from
    // this read-only view.
    setStagesInStore(detailStages);

    void fetchSharedTripRoute(code)
      .then((route) => {
        if (cancelled || !route) return;
        useTripStore.getState().applyRoute(route);
        setGeometryByDay(
          new Map(
            (route.stages ?? []).map((s) => [
              s.dayNumber,
              (s.geometry ?? []) as StageData["geometry"],
            ]),
          ),
        );
      })
      .catch(() => {});

    return () => {
      cancelled = true;
      // Reset the trip store so leaving the shared page does not leak
      // stages into a fresh planner session.
      clearTrip();
    };
  }, [code, detailStages, setStagesInStore, clearTrip]);

  const estimatedBudget = useMemo(
    () => computeEstimatedBudget(stages),
    [stages],
  );

  const handleStageClick = useCallback(
    (idx: number) => setFocusedStageIndex(idx),
    [],
  );
  const handleResetView = useCallback(() => setFocusedStageIndex(null), []);

  const showTimeline = viewMode === "timeline" || viewMode === "split";
  const showMap = viewMode === "map" || viewMode === "split";

  return (
    <ShareProvider value={{ shortCode: code, title: title ?? "" }}>
      <SiteChrome>
        <main className="max-w-[1200px] mx-auto px-4 md:px-6 py-6 md:py-8">
          <div className="space-y-6">
            {/* Permanent read-only banner — sits under the top bar. */}
            <SharedViewBanner />

            {/* Trip title (hero) + whole-trip downloads on the same line,
                aligned to the right — mirrors the edit view's TripActions
                placement (recette #649). The shared view is read-only, so only
                the GPX/FIT downloads are exposed (no undo/redo, share, config).
                Downloads resolve via the share short code (ShareContext). */}
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0 flex-1">
                {title && (
                  <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                )}
              </div>
              <div
                className="shrink-0 flex items-center gap-0.5 sm:gap-1"
                data-testid="trip-actions"
              >
                <TripDownloads tripId={undefined} tripTitle={title ?? ""} />
              </div>
            </div>

            {/* Summary */}
            <TripSummary
              totalDistance={totals.totalDistance}
              totalElevation={totals.totalElevation}
              totalElevationLoss={totals.totalElevationLoss}
              weather={stages[0]?.weather ?? null}
              isWeatherLoading={false}
              isProcessing={false}
              estimatedBudgetMin={estimatedBudget.min}
              estimatedBudgetMax={estimatedBudget.max}
              startDate={settings.startDate}
              endDate={settings.endDate}
              fatigueFactor={settings.fatigueFactor}
              elevationPenalty={settings.elevationPenalty}
              maxDistancePerDay={settings.maxDistancePerDay}
              averageSpeed={settings.averageSpeed}
              readOnly
            />

            {/* View mode toggle */}
            <div className="flex justify-end">
              <ViewModeToggle />
            </div>

            {/* Master/detail roadbook + map (read-only). The configuration
              panel, undo/redo, and "+" insertion controls are intentionally
              omitted in the shared view. */}
            <div
              className={[
                "flex gap-8",
                viewMode === "split" ? "lg:flex-row flex-col" : "",
              ].join(" ")}
              data-testid="split-view-container"
            >
              {showTimeline && (
                <div
                  className={
                    viewMode === "split" ? "lg:flex-1 lg:min-w-0" : "w-full"
                  }
                >
                  {stages.length > 0 ? (
                    <RoadbookMasterDetail
                      stages={stages}
                      startDate={settings.startDate}
                      isProcessing={false}
                      readOnly
                    />
                  ) : (
                    <p className="text-center text-muted-foreground">
                      {t("noStages")}
                    </p>
                  )}
                </div>
              )}

              {showMap && (
                <div
                  className={
                    viewMode === "split"
                      ? "lg:w-[520px] lg:shrink-0"
                      : "w-full h-[calc(100vh-12rem)]"
                  }
                >
                  <div
                    className={
                      viewMode === "split"
                        ? "h-[calc(100dvh-6rem)] lg:sticky lg:top-20"
                        : "w-full h-full"
                    }
                  >
                    <MapPanel
                      focusedStageIndex={focusedStageIndex}
                      onStageClick={handleStageClick}
                      onResetView={handleResetView}
                      stages={stages}
                    />
                  </div>
                </div>
              )}
            </div>
          </div>
        </main>
      </SiteChrome>
    </ShareProvider>
  );
}

export default function SharedTripPage({
  code,
  trip,
}: {
  code: string;
  trip: SharedTripDetail;
}) {
  return (
    <HydrationBoundary>
      <SharedTripLoader code={code} trip={trip} />
    </HydrationBoundary>
  );
}
