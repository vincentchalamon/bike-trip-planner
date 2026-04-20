"use client";

import { useCallback, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import dayjs from "dayjs";
import "dayjs/locale/fr";
import "dayjs/locale/en";
import {
  Loader2,
  Play,
  Settings,
  ArrowLeft,
  ArrowUp,
  Bike,
  Flag,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { TripHeader } from "@/components/trip-header";
import { TripSummary } from "@/components/trip-summary";
import { MapPanel } from "@/components/Map";
import { useUiStore } from "@/store/ui-store";
import type { StageData, WeatherData } from "@/lib/validation/schemas";

interface TripPreviewProps {
  title: string;
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  stages: StageData[];
  startDate: string | null;
  endDate: string | null;
  weather: WeatherData | null;
  isWeatherLoading?: boolean;
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
  /** Called when the user clicks "Lancer l'analyse". Should launch the
   *  Phase 2 enrichment pipeline and resolve to `true` on success. */
  onLaunchAnalysis: () => Promise<boolean>;
  /** Called when the user clicks "Changer d'itinéraire" — resets the trip
   *  and returns to the Acte 1 "Préparation" screen. */
  onChangeRoute: () => void;
  onTitleChange: (title: string) => void;
  /** When true, the trip header shows the feminist-name suggestion banner. */
  showTitleSuggestion?: boolean;
}

/**
 * Acte 1.5 — Preview screen. Sits between Acte 1 (Préparation) and Acte 2
 * (Analyse). Shows the coarse route (map + elevation profile + per-stage
 * breakdown) immediately after Phase 1 (`POST /trips` → pacing engine)
 * completes, so the user can validate the track before committing to the
 * expensive Phase 2 enrichments.
 *
 * Reuses {@link TripSummary}, {@link TripHeader}, and {@link MapPanel} so
 * the preview stays visually consistent with the full trip view. The
 * per-stage list is deliberately compact: distance + D+ + arrival label,
 * no accommodations/weather/alerts (those arrive in Phase 2).
 *
 * The three CTAs implement the action contract from issue #321:
 * - "Lancer l'analyse" → `POST /trips/{id}/analyze` (Acte 2)
 * - "Modifier les paramètres" → opens the shared {@link ConfigPanel}
 * - "Changer d'itinéraire" → `clearTrip()` and back to Acte 1
 */
export function TripPreview({
  title,
  totalDistance,
  totalElevation,
  totalElevationLoss,
  stages,
  startDate,
  endDate,
  weather,
  isWeatherLoading,
  fatigueFactor,
  elevationPenalty,
  maxDistancePerDay,
  averageSpeed,
  onLaunchAnalysis,
  onChangeRoute,
  onTitleChange,
  showTitleSuggestion,
}: TripPreviewProps) {
  const t = useTranslations("tripPreview");
  const locale = useLocale();
  const setConfigPanelOpen = useUiStore((s) => s.setConfigPanelOpen);
  const [isLaunching, setIsLaunching] = useState(false);

  const activeStages = stages.filter((s) => !s.isRestDay);
  const stagesCount = activeStages.length;

  const handleLaunchClick = useCallback(async () => {
    if (isLaunching) return;
    setIsLaunching(true);
    try {
      await onLaunchAnalysis();
    } finally {
      setIsLaunching(false);
    }
  }, [isLaunching, onLaunchAnalysis]);

  const handleOpenConfig = useCallback(() => {
    setConfigPanelOpen(true);
  }, [setConfigPanelOpen]);

  return (
    <section
      className="space-y-6"
      data-testid="trip-preview"
      aria-labelledby="trip-preview-heading"
    >
      {/* Title row — reuses TripHeader so the feminist-name suggestion
          banner behaves exactly like on the full trip view. */}
      <div className="text-center md:text-left">
        <TripHeader
          title={title}
          onTitleChange={onTitleChange}
          showTitleSuggestion={showTitleSuggestion}
        />
        <p className="mt-2 text-sm text-muted-foreground" id="trip-preview-heading">
          {t("subtitle")}
        </p>
      </div>

      {/* Global stats (distance, elevation, weather, dates, profile). */}
      <TripSummary
        totalDistance={totalDistance}
        totalElevation={totalElevation}
        totalElevationLoss={totalElevationLoss}
        weather={weather}
        isWeatherLoading={isWeatherLoading}
        isProcessing={false}
        startDate={startDate}
        endDate={endDate}
        fatigueFactor={fatigueFactor}
        elevationPenalty={elevationPenalty}
        maxDistancePerDay={maxDistancePerDay}
        averageSpeed={averageSpeed}
      />

      {/* Map + elevation profile — same component used on the full
          trip view so the user sees the exact tracé they'll get. */}
      <div
        className="relative w-full h-[420px] md:h-[520px] rounded-lg overflow-hidden border border-border bg-muted/10"
        data-testid="trip-preview-map"
      >
        <MapPanel
          focusedStageIndex={null}
          onStageClick={() => {
            /* preview: map clicks are visual-only */
          }}
          onResetView={() => {
            /* preview: nothing to reset */
          }}
        />
      </div>

      {/* Coarse stage breakdown */}
      <section
        className="space-y-3"
        aria-labelledby="trip-preview-stages-heading"
        data-testid="trip-preview-stages"
      >
        <div className="flex items-baseline justify-between">
          <h2
            id="trip-preview-stages-heading"
            className="text-lg font-semibold"
          >
            {t("stagesHeading")}
          </h2>
          <span
            className="text-sm text-muted-foreground"
            data-testid="trip-preview-stages-count"
          >
            {t("stagesCount", { count: stagesCount })}
          </span>
        </div>

        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {activeStages.map((stage, i) => (
            <li key={`preview-stage-${i}`}>
              <Card
                className="h-full"
                data-testid={`trip-preview-stage-${i}`}
              >
                <CardContent className="p-4 space-y-2">
                  <div className="flex items-center justify-between">
                    <span className="font-medium">
                      {t("stageDay", { day: stage.dayNumber })}
                    </span>
                    {startDate && (
                      <span className="text-xs text-muted-foreground">
                        {dayjs(startDate)
                          .add(stage.dayNumber - 1, "day")
                          .locale(locale)
                          .format("D MMM")}
                      </span>
                    )}
                  </div>
                  <div className="flex items-center gap-3 text-sm text-muted-foreground">
                    <span className="flex items-center gap-1">
                      <Bike className="h-3.5 w-3.5 text-brand" />
                      {t("stageDistance", {
                        distance: Math.round(stage.distance),
                      })}
                    </span>
                    <span className="flex items-center gap-1">
                      <ArrowUp className="h-3.5 w-3.5 text-red-500" />
                      {t("stageElevation", {
                        elevation: Math.round(stage.elevation),
                      })}
                    </span>
                  </div>
                  <div className="flex items-center gap-1 text-xs text-muted-foreground">
                    <Flag className="h-3 w-3 shrink-0" />
                    <span className="truncate">
                      {stage.endLabel
                        ? stage.endLabel
                        : t("stageEndPoint", {
                            lat: stage.endPoint.lat.toFixed(3),
                            lon: stage.endPoint.lon.toFixed(3),
                          })}
                    </span>
                  </div>
                </CardContent>
              </Card>
            </li>
          ))}
        </ul>
      </section>

      {/* Action bar — order matches the issue body:
          1. Launch analysis (primary), 2. Modify parameters, 3. Change route. */}
      <div
        className="flex flex-col sm:flex-row gap-3 sm:justify-end pt-2"
        data-testid="trip-preview-actions"
      >
        <Button
          type="button"
          variant="outline"
          size="lg"
          className="cursor-pointer sm:order-1 order-3"
          onClick={onChangeRoute}
          aria-label={t("changeRouteAria")}
          data-testid="trip-preview-change-route"
        >
          <ArrowLeft className="h-4 w-4" />
          {t("changeRoute")}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="lg"
          className="cursor-pointer sm:order-2 order-2"
          onClick={handleOpenConfig}
          aria-label={t("modifyParametersAria")}
          data-testid="trip-preview-modify-parameters"
        >
          <Settings className="h-4 w-4" />
          {t("modifyParameters")}
        </Button>
        <Button
          type="button"
          size="lg"
          className="cursor-pointer sm:order-3 order-1"
          onClick={handleLaunchClick}
          disabled={isLaunching || stagesCount === 0}
          aria-label={t("launchAnalysisAria")}
          data-testid="trip-preview-launch-analysis"
        >
          {isLaunching ? (
            <>
              <Loader2 className="h-4 w-4 animate-spin" />
              {t("launchingAnalysis")}
            </>
          ) : (
            <>
              <Play className="h-4 w-4" />
              {t("launchAnalysis")}
            </>
          )}
        </Button>
      </div>
    </section>
  );
}
