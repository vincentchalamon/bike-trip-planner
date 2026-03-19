"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Settings, HelpCircle } from "lucide-react";
import { MagicLinkInput } from "@/components/magic-link-input";
import { GpxUploadButton } from "@/components/gpx-upload-button";
import { TripSummary } from "@/components/trip-summary";
import { TripHeader } from "@/components/trip-header";
import { TripDownloads } from "@/components/trip-downloads";
import { StageProgressBar } from "@/components/stage-progress-bar";
import { Timeline } from "@/components/timeline";
import { ConfigPanel } from "@/components/config-panel";
import { KeyboardHelpModal } from "@/components/keyboard-help-modal";
import { TextExportButton } from "@/components/text-export-button";
import { MapPanel } from "@/components/Map";
import { ViewModeToggle } from "@/components/ViewModeToggle";
import { Button } from "@/components/ui/button";
import { UndoRedoButtons } from "@/components/undo-redo-buttons";
import { useTripPlanner } from "@/hooks/use-trip-planner";
import { useKeyboardShortcuts } from "@/hooks/use-keyboard-shortcuts";
import { useUiStore } from "@/store/ui-store";
import { useSwipe } from "@/hooks/use-swipe";
import {
  MEAL_COST_MIN,
  MEAL_COST_MAX,
  mealsForStage,
} from "@/lib/budget-constants";

export function TripPlanner() {
  const t = useTranslations();
  const {
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
    maxDistancePerDay,
    averageSpeed,
    ebikeMode,
    departureHour,
    enabledAccommodationTypes,
    handleAccommodationTypesChange,
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
    handlePacingCommit,
    handleEbikeModeChange,
    handleDepartureHourChange,
    handleAddAccommodation,
    handleSelectAccommodation,
    handleDeselectAccommodation,
    handleExpandAccommodationRadius,
    handleInsertRestDay,
    handleAddPoiWaypoint,
    clearNewAccKey,
  } = useTripPlanner();

  const setConfigPanelOpen = useUiStore((s) => s.setConfigPanelOpen);
  const setHelpModalOpen = useUiStore((s) => s.setHelpModalOpen);
  const focusedMapStageIndex = useUiStore((s) => s.focusedMapStageIndex);
  const setFocusedMapStageIndex = useUiStore((s) => s.setFocusedMapStageIndex);
  const viewMode = useUiStore((s) => s.viewMode);
  const setViewMode = useUiStore((s) => s.setViewMode);
  const activeStages = useMemo(
    () => stages.filter((s) => !s.isRestDay),
    [stages],
  );
  const hasMap = activeStages.length > 0;

  // Register global keyboard shortcuts (Escape, ?, J/K)
  useKeyboardShortcuts(activeStages.length);

  const setHoveredAccommodation = useUiStore((s) => s.setHoveredAccommodation);

  const handleAccommodationHover = useCallback(
    (stageOriginalIndex: number, accIndex: number | null) => {
      if (accIndex === null) {
        setHoveredAccommodation(null);
        return;
      }
      // Convert originalIndex (in stages, including rest days) to activeStages index
      let activeIdx = 0;
      for (let i = 0; i < stages.length; i++) {
        if (i === stageOriginalIndex) break;
        if (!stages[i]?.isRestDay) activeIdx++;
      }
      setHoveredAccommodation({ stageIndex: activeIdx, accIndex });
    },
    [setHoveredAccommodation, stages],
  );

  const handleMapStageClick = useCallback(
    (stageIndex: number) => {
      setFocusedMapStageIndex(stageIndex);
    },
    [setFocusedMapStageIndex],
  );

  const handleMapResetView = useCallback(() => {
    setFocusedMapStageIndex(null);
  }, [setFocusedMapStageIndex]);

  // E2E test hook: allows Playwright to set the focused map stage via CustomEvent
  // (works in production builds unlike window.__zustand_ui_store which is guarded by NODE_ENV)
  useEffect(() => {
    const handler = (e: Event) => {
      const index = (e as CustomEvent<number | null>).detail;
      setFocusedMapStageIndex(typeof index === "number" ? index : null);
    };
    window.addEventListener("__test_set_focused_map_stage", handler);
    return () =>
      window.removeEventListener("__test_set_focused_map_stage", handler);
  }, [setFocusedMapStageIndex]);

  // Mobile swipe: left → map, right → timeline (cycle: timeline ↔ map on mobile)
  const swipeHandlers = useSwipe({
    onSwipeLeft: useCallback(() => {
      if (viewMode === "timeline") setViewMode("map");
    }, [viewMode, setViewMode]),
    onSwipeRight: useCallback(() => {
      if (viewMode === "map") setViewMode("timeline");
    }, [viewMode, setViewMode]),
  });

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

  // Show the sticky progress bar only when its natural position has scrolled
  // off the top of the viewport. An IntersectionObserver watches an invisible
  // sentinel div placed where the bar would normally sit.
  const sentinelRef = useRef<HTMLDivElement>(null);
  const fixedHeaderRef = useRef<HTMLDivElement>(null);
  const [isScrolledPast, setIsScrolledPast] = useState(false);
  const [fixedHeaderHeight, setFixedHeaderHeight] = useState(0);
  const hasTripData = !!trip;

  useEffect(() => {
    const sentinel = sentinelRef.current;
    if (!sentinel) return;
    const observer = new IntersectionObserver(
      ([entry]) => {
        setIsScrolledPast(!entry?.isIntersecting);
      },
      { threshold: 0 },
    );
    observer.observe(sentinel);
    return () => observer.disconnect();
  }, [hasTripData]);

  // Track the fixed header height so the sticky map can offset accordingly.
  useEffect(() => {
    const el = fixedHeaderRef.current;
    if (!el) return;
    const observer = new ResizeObserver(([entry]) => {
      setFixedHeaderHeight(
        entry?.borderBoxSize?.[0]?.blockSize ?? el.offsetHeight,
      );
    });
    observer.observe(el);
    // Seed the height synchronously so that the map offset is correct on the
    // very first scroll that triggers isScrolledPast, before ResizeObserver fires.
    setFixedHeaderHeight(el.offsetHeight);
    return () => observer.disconnect();
  }, []);

  return (
    <main className="max-w-[1200px] mx-auto px-4 md:px-6 py-8 md:py-12 relative overflow-x-clip">
      {/* Skip link */}
      <a
        href="#timeline"
        className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-background focus:p-2 focus:rounded"
      >
        {t("layout.skipToTimeline")}
      </a>

      {/* Toolbar: magic link + GPX upload + undo/redo + config button */}
      <div className="flex items-center gap-2">
        <div className="flex-1 min-w-0">
          <MagicLinkInput
            onSubmit={handleMagicLink}
            isProcessing={isProcessing}
            disabled={false}
          />
        </div>
        <GpxUploadButton onUpload={handleGpxUpload} disabled={isProcessing} />
        <UndoRedoButtons />
        <Button
          variant="ghost"
          size="icon"
          className="h-9 w-9 cursor-pointer"
          onClick={() => setHelpModalOpen(true)}
          title={t("keyboardHelp.openButton")}
          aria-label={t("keyboardHelp.openButton")}
          data-testid="help-button"
        >
          <HelpCircle className="h-4 w-4" />
        </Button>
        <Button
          variant="ghost"
          size="icon"
          className="h-9 w-9 cursor-pointer"
          onClick={() => setConfigPanelOpen(true)}
          title={t("config.open")}
          aria-label={t("config.open")}
          data-testid="config-open-button"
        >
          <Settings className="h-4 w-4" />
        </Button>
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
            estimatedBudgetMin={estimatedBudget.min}
            estimatedBudgetMax={estimatedBudget.max}
          />

          {/* Header: title + locations + calendar */}
          <TripHeader
            title={trip.title}
            onTitleChange={updateTitle}
            startDate={startDate}
            endDate={endDate}
            onDatesChange={handleDatesChange}
            showTitleSuggestion={totalDistance !== null}
            isTitleLoading={isProcessing && totalDistance === null}
          >
            <TripDownloads tripId={trip.id} tripTitle={trip.title} />
            {totalDistance !== null && (
              <TextExportButton
                title={trip.title}
                totalDistance={totalDistance}
                totalElevation={totalElevation}
                totalElevationLoss={totalElevationLoss}
                sourceUrl={trip.sourceUrl}
                stages={stages}
                startDate={startDate}
              />
            )}
          </TripHeader>

          {/* Sentinel — marks the natural position of the progress bar in the
              flow. The sticky bar becomes visible once this exits the viewport. */}
          <div ref={sentinelRef} aria-hidden="true" />

          {/* View mode toggle — only relevant when a map is available */}
          {hasMap && (
            <div className="flex justify-end">
              <ViewModeToggle />
            </div>
          )}

          {/* Fixed header — visible after scrolling past the sentinel.
              Contains the segmented progress bar (timeline/split only) and
              the view mode toggle (all modes), so both remain accessible
              while the user is deep in the timeline. */}
          <div
            ref={fixedHeaderRef}
            className={[
              "fixed top-0 left-0 right-0 z-20 bg-background border-b border-border",
              "transition-transform duration-200",
              isScrolledPast ? "" : "-translate-y-full pointer-events-none",
            ].join(" ")}
          >
            <div className="max-w-[1200px] mx-auto px-4 md:px-6 py-2 flex flex-col gap-1">
              {/* Progress bar — hidden in map-only mode */}
              {(!hasMap || viewMode !== "map") && (
                <div className="w-full">
                  <StageProgressBar />
                </div>
              )}
              {hasMap && (
                <div className="flex justify-end">
                  <ViewModeToggle testId="view-mode-toggle-sticky" />
                </div>
              )}
            </div>
          </div>

          {/* Split view: timeline (left) + map (right, sticky) */}
          {/* Swipe handlers enable left/right swipe between map and timeline on mobile */}
          <div
            className={[
              "flex gap-8",
              hasMap && viewMode === "split" ? "lg:flex-row flex-col" : "",
            ].join(" ")}
            {...(hasMap ? swipeHandlers : {})}
            data-testid="split-view-container"
          >
            {/* Timeline — hidden in "map" mode (only when a map is available) */}
            {(!hasMap || viewMode === "timeline" || viewMode === "split") && (
              <div
                id="timeline"
                className={
                  hasMap && viewMode === "split"
                    ? "lg:flex-1 lg:min-w-0"
                    : "w-full"
                }
              >
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
                  onSelectAccommodation={handleSelectAccommodation}
                  onDeselectAccommodation={handleDeselectAccommodation}
                  onExpandAccommodationRadius={handleExpandAccommodationRadius}
                  onInsertRestDay={handleInsertRestDay}
                  onAddPoiWaypoint={handleAddPoiWaypoint}
                  onAccommodationHover={handleAccommodationHover}
                  newAccKey={newAccKey}
                  onClearNewAcc={clearNewAccKey}
                />
              </div>
            )}

            {/* Map panel — hidden in "timeline" mode; sticky on desktop */}
            {hasMap && (viewMode === "map" || viewMode === "split") && (
              <div
                className={
                  viewMode === "split" ? "lg:w-[520px] lg:shrink-0" : "w-full"
                }
              >
                <div
                  className={viewMode === "split" ? "lg:sticky" : "sticky"}
                  style={{
                    top: isScrolledPast ? `${fixedHeaderHeight + 8}px` : "1rem",
                    height: `calc(100vh - ${isScrolledPast ? fixedHeaderHeight + 8 : 16}px)`,
                  }}
                  data-testid="map-container"
                  data-focused-stage={focusedMapStageIndex ?? ""}
                >
                  <MapPanel
                    focusedStageIndex={focusedMapStageIndex}
                    onStageClick={handleMapStageClick}
                    onResetView={handleMapResetView}
                  />
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Configuration panel (sidebar drawer) */}
      <ConfigPanel
        fatigueFactor={fatigueFactor}
        elevationPenalty={elevationPenalty}
        maxDistancePerDay={maxDistancePerDay}
        averageSpeed={averageSpeed}
        ebikeMode={ebikeMode}
        departureHour={departureHour}
        enabledAccommodationTypes={enabledAccommodationTypes}
        onPacingUpdate={handlePacingChange}
        onPacingCommit={handlePacingCommit}
        onEbikeModeChange={handleEbikeModeChange}
        onDepartureHourChange={handleDepartureHourChange}
        onAccommodationTypesChange={handleAccommodationTypesChange}
      />

      {/* Keyboard shortcuts help modal */}
      <KeyboardHelpModal />
    </main>
  );
}
