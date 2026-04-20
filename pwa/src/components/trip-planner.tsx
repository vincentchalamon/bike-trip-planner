"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Settings, HelpCircle, Loader2, X, Share2, Map } from "lucide-react";
import { CardSelection } from "@/components/card-selection";
import { GpxDropZone } from "@/components/gpx-drop-zone";
import { TripLockedBanner } from "@/components/trip-locked-banner";
import { TripPreview } from "@/components/trip-preview";
import { TripSummary } from "@/components/trip-summary";
import { TripHeader } from "@/components/trip-header";
import { TripDownloads } from "@/components/trip-downloads";
import { StageProgressBar } from "@/components/stage-progress-bar";
import { Timeline } from "@/components/timeline";
import { ConfigPanel } from "@/components/config-panel";
import { KeyboardHelpModal } from "@/components/keyboard-help-modal";
import { ShareModal } from "@/components/share-modal";
import { MapPanel } from "@/components/Map";
import { ViewModeToggle } from "@/components/ViewModeToggle";
import { Button } from "@/components/ui/button";
import { UndoRedoButtons } from "@/components/undo-redo-buttons";
import { Stepper } from "@/components/stepper";
import { RecentTrips } from "@/components/recent-trips";
import { SavedTripsSection } from "@/components/saved-trips-section";
import { OfflineBanner } from "@/components/offline-banner";
import { AttributionFooter } from "@/components/attribution-footer";
import { useTripPlanner } from "@/hooks/use-trip-planner";
import { useLinkParam } from "@/hooks/use-link-param";
import { useKeyboardShortcuts } from "@/hooks/use-keyboard-shortcuts";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { useOfflineStore } from "@/store/offline-store";
import { useSwipe } from "@/hooks/use-swipe";
import {
  MEAL_COST_MIN,
  MEAL_COST_MAX,
  mealsForStage,
} from "@/lib/budget-constants";

export function TripPlanner({ onClose }: { onClose?: () => void } = {}) {
  const t = useTranslations();
  const router = useRouter();
  const clearTrip = useTripStore((s) => s.clearTrip);
  const isOnline = useOfflineStore((s) => s.isOnline);

  const {
    trip,
    isLocked,
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
    handleTitleChange,
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
    handleDuplicateTrip,
    handleLaunchAnalysis,
    handleShareTrip,
    isShareModalOpen,
    setShareModalOpen,
    clearNewAccKey,
  } = useTripPlanner();

  // Auto-submit when ?link= query param is present
  useLinkParam(handleMagicLink);

  const setConfigPanelOpen = useUiStore((s) => s.setConfigPanelOpen);
  const setHelpModalOpen = useUiStore((s) => s.setHelpModalOpen);
  const focusedMapStageIndex = useUiStore((s) => s.focusedMapStageIndex);
  const setFocusedMapStageIndex = useUiStore((s) => s.setFocusedMapStageIndex);
  const viewMode = useUiStore((s) => s.viewMode);
  const setViewMode = useUiStore((s) => s.setViewMode);
  const goToStep = useUiStore((s) => s.goToStep);
  const completeStep = useUiStore((s) => s.completeStep);
  const resetStepper = useUiStore((s) => s.resetStepper);
  const hasAnalysisStarted = useUiStore((s) => s.hasAnalysisStarted);
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

  // Drive stepper state transitions based on trip lifecycle:
  // - Processing (URL submit / GPX upload) → "analysis"
  // - Stages computed, processing settled, analysis not launched → "preview"
  // - Stages computed, analysis complete → "my_trip"
  useEffect(() => {
    if (isProcessing) {
      // Computation in flight: advance past preparation/preview into analysis.
      completeStep("preparation");
      completeStep("preview");
      goToStep("analysis");
    } else if (trip && stages.length > 0 && !hasAnalysisStarted) {
      // Phase 1 complete: pacing engine produced stages. Park on "preview"
      // and wait for the user to explicitly click "Lancer l'analyse".
      completeStep("preparation");
      goToStep("preview");
    } else if (trip && stages.length > 0 && hasAnalysisStarted) {
      // Phase 2 complete: every prior step done, advance to my_trip.
      completeStep("preparation");
      completeStep("preview");
      completeStep("analysis");
      goToStep("my_trip");
    } else if (trip && stages.length === 0) {
      // Trip identity loaded but no stages yet: preview state (loading).
      completeStep("preparation");
      goToStep("preview");
    } else {
      // No trip, not processing: trip was cleared (or initial mount) — rewind
      // past the "my_trip" lock and back to "preparation".
      resetStepper();
    }
  }, [
    isProcessing,
    trip,
    stages.length,
    hasAnalysisStarted,
    completeStep,
    goToStep,
    resetStepper,
  ]);

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
        const scrolled = !entry?.isIntersecting;
        setIsScrolledPast(scrolled);
        // Re-read header height after the next paint so the DOM reflects
        // the new translate state and any content changes.
        if (scrolled) {
          requestAnimationFrame(() => {
            if (fixedHeaderRef.current) {
              setFixedHeaderHeight(fixedHeaderRef.current.offsetHeight);
            }
          });
        }
      },
      { threshold: 0 },
    );
    observer.observe(sentinel);
    return () => observer.disconnect();
  }, [hasTripData]);

  // Keep fixedHeaderHeight in sync when the header content changes
  // (e.g. progress bar appears/disappears depending on viewMode).
  useEffect(() => {
    const el = fixedHeaderRef.current;
    if (!el) return;
    const observer = new ResizeObserver(([entry]) => {
      setFixedHeaderHeight(
        entry?.borderBoxSize?.[0]?.blockSize ?? el.offsetHeight,
      );
    });
    observer.observe(el);
    setFixedHeaderHeight(el.offsetHeight);
    return () => observer.disconnect();
  }, [hasMap, viewMode]);

  // Derive the UI states (welcome / loading / preview / full trip view).
  // The preview screen (Acte 1.5) sits between Phase 1 (pacing engine) and
  // Phase 2 (enrichment) — it is active once the backend has produced
  // stages AND the initial processing has settled AND the user has not yet
  // clicked "Lancer l'analyse". The `trip_complete` event flips
  // `hasAnalysisStarted` to `true`, which keeps the legacy single-phase
  // flow (used in most mocked tests) rendering the full trip view.
  const isWelcome = !trip && !isProcessing;
  const isLoading = !trip && isProcessing;
  const isPreview =
    !!trip &&
    !isProcessing &&
    activeStages.length > 0 &&
    !hasAnalysisStarted;
  const clearTripAndReset = useCallback(() => {
    clearTrip();
    useUiStore.getState().setProcessing(false);
    useUiStore.getState().setAccommodationScanning(false);
    useUiStore.getState().setAnalysisStarted(false);
  }, [clearTrip]);

  const tNav = useTranslations("navigation");

  // Action buttons shared across all states
  const actionButtons = (
    <div className="flex items-center gap-1">
      {/* "Mes voyages" link — visible only when authenticated and no trip is open */}
      {!trip && (
        <Button
          asChild
          variant="ghost"
          size="sm"
          className="h-9 gap-1 cursor-pointer"
          data-testid="my-trips-link"
        >
          <Link href="/trips">
            <Map className="h-4 w-4" />
            <span className="hidden sm:inline">{tNav("myTrips")}</span>
          </Link>
        </Button>
      )}
      {trip && <TripDownloads tripId={trip.id} tripTitle={trip.title} />}
      {trip && (
        <Button
          variant="ghost"
          size="icon"
          className="h-9 w-9 cursor-pointer"
          onClick={() => setShareModalOpen(true)}
          title={t("share.title")}
          aria-label={t("share.title")}
          data-testid="share-button"
        >
          <Share2 className="h-4 w-4" />
        </Button>
      )}
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
  );

  return (
    <GpxDropZone
      onDrop={handleGpxUpload}
      disabled={isProcessing || !!trip || !isOnline}
    >
      <main className="max-w-[1200px] mx-auto px-4 md:px-6 py-8 md:py-12 relative overflow-x-clip">
        {/* Skip link */}
        <a
          href="#timeline"
          className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-background focus:p-2 focus:rounded"
        >
          {t("layout.skipToTimeline")}
        </a>

        {/* Offline status banner */}
        <OfflineBanner />

        {/* Stepper — visible in all planning states (welcome, loading, trip loaded).
            Hidden on landing page, FAQ and trips list since those pages don't
            render TripPlanner at all. */}
        <div className="mb-8 pb-6" data-testid="stepper-wrapper">
          <Stepper />
        </div>

        {/* === State 1: Welcome (no trip, not processing) === */}
        {isWelcome && (
          <div className="flex flex-col items-center justify-center min-h-[60vh] gap-6">
            <CardSelection
              onSubmitUrl={handleMagicLink}
              onUploadFile={handleGpxUpload}
              disabled={!isOnline}
            />
            {actionButtons}
            <RecentTrips />
            <SavedTripsSection />
            <footer className="mt-4 text-center space-y-2">
              <div>
                <Link
                  href="/faq"
                  className="text-xs text-muted-foreground hover:text-foreground transition-colors"
                  data-testid="footer-faq-link"
                >
                  {t("footer.faq")}
                </Link>
              </div>
              <div>
                <AttributionFooter />
              </div>
            </footer>
          </div>
        )}

        {/* === State 2: Loading (URL submitted or GPX uploading) === */}
        {isLoading && (
          <div className="flex flex-col items-center justify-center min-h-[60vh] gap-6">
            <Loader2 className="h-10 w-10 text-brand animate-spin" />
            {actionButtons}
          </div>
        )}

        {/* === State 3a: Preview (Acte 1.5 — stages computed, analysis
             not yet launched). Inserts a user-controlled gate between
             Phase 1 (pacing engine) and Phase 2 (enrichment pipeline). */}
        {isPreview && (
          <>
            <Button
              variant="ghost"
              size="icon"
              className="absolute top-2 right-4 md:right-6 h-8 w-8 z-10 text-muted-foreground hover:text-foreground"
              onClick={() => {
                if (onClose) {
                  onClose();
                } else {
                  clearTripAndReset();
                  router.push("/");
                }
              }}
              title={t("planner.closeTrip")}
              aria-label={t("planner.closeTrip")}
              data-testid="close-trip-button-preview"
            >
              <X className="h-4 w-4" />
            </Button>

            <TripPreview
              title={trip?.title ?? ""}
              totalDistance={totalDistance}
              totalElevation={totalElevation}
              totalElevationLoss={totalElevationLoss}
              stages={stages}
              startDate={startDate}
              endDate={endDate}
              weather={firstWeather}
              isWeatherLoading={isWeatherLoading}
              fatigueFactor={fatigueFactor}
              elevationPenalty={elevationPenalty}
              maxDistancePerDay={maxDistancePerDay}
              averageSpeed={averageSpeed}
              onLaunchAnalysis={handleLaunchAnalysis}
              onChangeRoute={() => {
                clearTripAndReset();
                router.push("/");
              }}
              onTitleChange={handleTitleChange}
              showTitleSuggestion={totalDistance !== null}
            />
          </>
        )}

        {/* === State 3b: Trip loaded — full view (shown once the user
             has launched the Phase 2 analysis via the preview CTA). === */}
        {trip && !isPreview && (
          <>
            {/* Close button — top-right corner */}
            <Button
              variant="ghost"
              size="icon"
              className="absolute top-2 right-4 md:right-6 h-8 w-8 z-10 text-muted-foreground hover:text-foreground"
              onClick={() => {
                if (onClose) {
                  onClose();
                } else {
                  clearTripAndReset();
                  router.push("/");
                }
              }}
              title={t("planner.closeTrip")}
              aria-label={t("planner.closeTrip")}
              data-testid="close-trip-button"
            >
              <X className="h-4 w-4" />
            </Button>

            {/* Top bar: title on first row, action buttons on second row */}
            <div className="flex flex-wrap items-center justify-center md:justify-start gap-x-2 gap-y-2">
              <div className="basis-full min-w-0 text-center md:text-left">
                <TripHeader
                  title={trip.title}
                  onTitleChange={handleTitleChange}
                  showTitleSuggestion={totalDistance !== null}
                  isTitleLoading={isProcessing && totalDistance === null}
                />
              </div>
              {actionButtons}
            </div>

            {/* Locked banner */}
            {isLocked && (
              <div className="mt-4">
                <TripLockedBanner />
              </div>
            )}

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
                startDate={startDate}
                endDate={endDate}
                fatigueFactor={fatigueFactor}
                elevationPenalty={elevationPenalty}
                maxDistancePerDay={maxDistancePerDay}
                averageSpeed={averageSpeed}
              />

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
                {(!hasMap ||
                  viewMode === "timeline" ||
                  viewMode === "split") && (
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
                      readOnly={isLocked || !isOnline}
                      onDeleteStage={handleDeleteStage}
                      onAddStage={handleAddStage}
                      onDistanceChange={handleDistanceChange}
                      onAddAccommodation={handleAddAccommodation}
                      onUpdateAccommodation={updateLocalAccommodation}
                      onRemoveAccommodation={removeLocalAccommodation}
                      onSelectAccommodation={handleSelectAccommodation}
                      onDeselectAccommodation={handleDeselectAccommodation}
                      onExpandAccommodationRadius={
                        handleExpandAccommodationRadius
                      }
                      onInsertRestDay={handleInsertRestDay}
                      onAddPoiWaypoint={handleAddPoiWaypoint}
                      onAccommodationHover={handleAccommodationHover}
                      newAccKey={newAccKey}
                      onClearNewAcc={clearNewAccKey}
                      onOpenConfig={() => setConfigPanelOpen(true)}
                    />
                  </div>
                )}

                {/* Map panel — hidden in "timeline" mode; sticky on desktop */}
                {hasMap && (viewMode === "map" || viewMode === "split") && (
                  <div
                    className={
                      viewMode === "split"
                        ? "lg:w-[520px] lg:shrink-0"
                        : "w-full"
                    }
                  >
                    <div
                      className={viewMode === "split" ? "lg:sticky" : "sticky"}
                      style={{
                        top: isScrolledPast
                          ? `${fixedHeaderHeight + 12}px`
                          : "0.5rem",
                        height: isScrolledPast
                          ? `calc(100dvh - ${fixedHeaderHeight + 12}px)`
                          : "calc(100dvh - 0.5rem)",
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
          </>
        )}

        {/* Share modal */}
        {trip && (
          <ShareModal
            open={isShareModalOpen}
            onOpenChange={setShareModalOpen}
            tripId={trip.id}
            title={trip.title}
            sourceUrl={trip.sourceUrl}
            totalDistance={totalDistance}
            totalElevation={totalElevation}
            totalElevationLoss={totalElevationLoss}
            stages={stages}
            startDate={startDate}
            endDate={endDate}
            estimatedBudgetMin={estimatedBudget.min}
            estimatedBudgetMax={estimatedBudget.max}
          />
        )}

        {/* Configuration panel (sidebar drawer) */}
        <ConfigPanel
          startDate={startDate}
          endDate={endDate}
          onDatesChange={handleDatesChange}
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
          readOnly={isLocked || !isOnline}
          hasTripLoaded={!!trip}
          onDuplicate={handleDuplicateTrip}
          onShare={handleShareTrip}
        />

        {/* Keyboard shortcuts help modal */}
        <KeyboardHelpModal />
      </main>
    </GpxDropZone>
  );
}
