"use client";

import { use, useEffect, useMemo, useState, useCallback } from "react";
import { useTranslations } from "next-intl";
import Link from "next/link";
import { Suspense } from "react";
import { ArrowLeft, Info, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Timeline } from "@/components/timeline";
import { TripSummary } from "@/components/trip-summary";
import { TripDownloads } from "@/components/trip-downloads";
import { MapPanel } from "@/components/Map/MapPanel";
import { ViewModeToggle } from "@/components/ViewModeToggle";
import { HydrationBoundary } from "@/components/hydration-boundary";
import { fetchSharedTrip } from "@/lib/api/client";
import { ShareProvider } from "@/lib/share-context";
import { useUiStore } from "@/store/ui-store";
import {
  MEAL_COST_MIN,
  MEAL_COST_MAX,
  mealsForStage,
} from "@/lib/budget-constants";
import type { StageData } from "@/lib/validation/schemas";

function SharedTripLoader({ code }: { code: string }) {
  const t = useTranslations("sharePage");
  const viewMode = useUiStore((s) => s.viewMode);
  const [loadError, setLoadError] = useState(false);
  const [isLoaded, setIsLoaded] = useState(false);
  const [title, setTitle] = useState<string | null>(null);
  const [stages, setStages] = useState<StageData[]>([]);
  const [startDate, setStartDate] = useState<string | null>(null);
  const [endDate, setEndDate] = useState<string | null>(null);
  const [totalDistance, setTotalDistance] = useState<number | null>(null);
  const [totalElevation, setTotalElevation] = useState<number | null>(null);
  const [totalElevationLoss, setTotalElevationLoss] = useState<number | null>(
    null,
  );
  const [pacingConfig, setPacingConfig] = useState<{
    fatigueFactor: number;
    elevationPenalty: number;
    maxDistancePerDay: number;
    averageSpeed: number;
  } | null>(null);
  const [focusedStageIndex, setFocusedStageIndex] = useState<number | null>(
    null,
  );

  useEffect(() => {
    let cancelled = false;

    async function loadSharedTrip() {
      try {
        const data = await fetchSharedTrip(code);

        if (!data || cancelled) {
          if (!cancelled) setLoadError(true);
          return;
        }

        setTitle(data.title ?? null);
        setStartDate(data.startDate?.split("T")[0] ?? null);
        setEndDate(data.endDate?.split("T")[0] ?? null);

        const parsedStages: StageData[] = (data.stages ?? []).map((s) => ({
          dayNumber: (s.dayNumber as number) ?? 0,
          distance: (s.distance as number) ?? 0,
          elevation: (s.elevation as number) ?? 0,
          elevationLoss: (s.elevationLoss as number) ?? 0,
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
          label: (s.label as string) ?? null,
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
          isRestDay: (s.isRestDay as boolean) ?? false,
          supplyTimeline: [],
        }));

        setStages(parsedStages);

        const activeStages = parsedStages.filter((s) => !s.isRestDay);
        setTotalDistance(activeStages.reduce((sum, s) => sum + s.distance, 0));
        setTotalElevation(
          activeStages.reduce((sum, s) => sum + s.elevation, 0),
        );
        setTotalElevationLoss(
          parsedStages.reduce((sum, s) => sum + (s.elevationLoss ?? 0), 0),
        );

        setPacingConfig({
          fatigueFactor: data.fatigueFactor ?? 0.9,
          elevationPenalty: data.elevationPenalty ?? 50,
          maxDistancePerDay: data.maxDistancePerDay ?? 80,
          averageSpeed: data.averageSpeed ?? 15,
        });

        setIsLoaded(true);
      } catch {
        if (!cancelled) setLoadError(true);
      }
    }

    void loadSharedTrip();

    return () => {
      cancelled = true;
    };
  }, [code]);

  const estimatedBudget = useMemo(() => {
    const nonRestStages = stages.filter((s) => !s.isRestDay);
    const lastActiveIndex = nonRestStages.length - 1;
    const restDayCount = stages.filter((s) => s.isRestDay).length;
    let accMin = 0;
    let accMax = 0;
    let foodMin = restDayCount * 3 * MEAL_COST_MIN;
    let foodMax = restDayCount * 3 * MEAL_COST_MAX;
    nonRestStages.forEach((s, i) => {
      const isFirst = i === 0;
      const isLast = i === lastActiveIndex;
      foodMin += mealsForStage(isFirst, isLast) * MEAL_COST_MIN;
      foodMax += mealsForStage(isFirst, isLast) * MEAL_COST_MAX;
      if (!isLast) {
        if (s.selectedAccommodation) {
          accMin += s.selectedAccommodation.estimatedPriceMin ?? 0;
          accMax += s.selectedAccommodation.estimatedPriceMax ?? 0;
        } else if (s.accommodations.length > 0) {
          accMin +=
            s.accommodations.reduce((a, ac) => a + ac.estimatedPriceMin, 0) /
            s.accommodations.length;
          accMax +=
            s.accommodations.reduce((a, ac) => a + ac.estimatedPriceMax, 0) /
            s.accommodations.length;
        }
      }
    });
    return { min: accMin + foodMin, max: accMax + foodMax };
  }, [stages]);

  const handleStageClick = useCallback(
    (idx: number) => setFocusedStageIndex(idx),
    [],
  );
  const handleResetView = useCallback(() => setFocusedStageIndex(null), []);

  if (loadError) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[60vh] gap-4">
        <p className="text-destructive" data-testid="share-error">
          {t("error")}
        </p>
        <Button asChild variant="outline">
          <Link href="/">
            <ArrowLeft className="h-4 w-4 mr-2" />
            {t("backToHome")}
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

  const showTimeline = viewMode === "timeline" || viewMode === "split";
  const showMap = viewMode === "map" || viewMode === "split";

  return (
    <ShareProvider value={{ shortCode: code, title: title ?? "" }}>
      <div className="space-y-6">
        {/* Read-only banner */}
        <div
          data-testid="read-only-banner"
          className="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300"
        >
          <Info className="h-4 w-4 shrink-0" />
          {t("readOnlyBanner")}
        </div>

        {/* Title + downloads */}
        <div className="flex items-center gap-3">
          {title && (
            <h1 className="text-2xl font-bold tracking-tight flex-1">
              {title}
            </h1>
          )}
          <TripDownloads tripId={undefined} tripTitle={title ?? ""} />
        </div>

        {/* Summary */}
        <TripSummary
          totalDistance={totalDistance}
          totalElevation={totalElevation}
          totalElevationLoss={totalElevationLoss}
          weather={stages[0]?.weather ?? null}
          isWeatherLoading={false}
          isProcessing={false}
          estimatedBudgetMin={estimatedBudget.min}
          estimatedBudgetMax={estimatedBudget.max}
          startDate={startDate}
          endDate={endDate}
          fatigueFactor={pacingConfig?.fatigueFactor ?? 0.9}
          elevationPenalty={pacingConfig?.elevationPenalty ?? 50}
          maxDistancePerDay={pacingConfig?.maxDistancePerDay ?? 80}
          averageSpeed={pacingConfig?.averageSpeed ?? 15}
        />

        {/* View mode toggle */}
        <ViewModeToggle />

        {/* Timeline + Map */}
        <div className="flex gap-8 relative">
          {showTimeline && (
            <div
              className={
                viewMode === "split"
                  ? "flex-1 min-w-0"
                  : "w-full max-w-[900px] mx-auto"
              }
            >
              {stages.length > 0 ? (
                <Timeline
                  stages={stages}
                  startDate={startDate}
                  isProcessing={false}
                  readOnly
                  onDeleteStage={() => {}}
                  onAddStage={() => {}}
                  onAddAccommodation={() => {}}
                  onUpdateAccommodation={() => {}}
                  onRemoveAccommodation={() => {}}
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
                  ? "hidden lg:block lg:w-[520px] lg:sticky lg:top-4 lg:h-[calc(100vh-6rem)]"
                  : "w-full h-[calc(100vh-12rem)]"
              }
            >
              <MapPanel
                focusedStageIndex={focusedStageIndex}
                onStageClick={handleStageClick}
                onResetView={handleResetView}
                stages={stages}
              />
            </div>
          )}
        </div>
      </div>
    </ShareProvider>
  );
}

export default function SharedTripPage({
  params,
}: {
  params: Promise<{ code: string }>;
}) {
  const { code } = use(params);

  return (
    <HydrationBoundary>
      <main className="max-w-[1200px] mx-auto px-4 md:px-6 py-8 md:py-12">
        <Suspense fallback={null}>
          <SharedTripLoader code={code} />
        </Suspense>
      </main>
    </HydrationBoundary>
  );
}
