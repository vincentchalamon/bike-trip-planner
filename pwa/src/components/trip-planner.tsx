"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { CardSelection } from "@/components/card-selection";
import { GpxDropZone } from "@/components/gpx-drop-zone";
import { TripLockedBanner } from "@/components/trip-locked-banner";
import { OutOfZoneBanner } from "@/components/out-of-zone-banner";
import { TripSummary } from "@/components/trip-summary";
import { TripAiOverview } from "@/components/trip-ai-overview";
import { AiUnavailableNotice } from "@/components/ai-unavailable-notice";
import { TripHeader } from "@/components/trip-header";
import { AiBubble } from "@/components/ai-bubble";
import { useAiSettings } from "@/hooks/use-ai-settings";
import { RoadbookMasterDetail } from "@/components/Timeline";
import { ConfigPanel } from "@/components/config-panel";
import { TripActions } from "@/components/trip-actions";
import { ShareModal } from "@/components/share-modal";
import dynamic from "next/dynamic";
import { ViewModeToggle } from "@/components/ViewModeToggle";
import { InlineRecomputationBar } from "@/components/inline-recomputation-bar";
import { ModificationQueue } from "@/components/modification-queue";
import { RecentTrips } from "@/components/recent-trips";
import { OfflineBanner } from "@/components/offline-banner";
import { useTripPlanner } from "@/hooks/use-trip-planner";
import { useLinkParam } from "@/hooks/use-link-param";
import { useKeyboardShortcuts } from "@/hooks/use-keyboard-shortcuts";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { useOfflineStore } from "@/store/offline-store";
import { useSwipe } from "@/hooks/use-swipe";
import { fetchAiAvailability } from "@/lib/ai-availability";
import { isAiFeatureEnabled } from "@/lib/constants";
import {
  MEAL_COST_MIN,
  MEAL_COST_MAX,
  mealsForStage,
} from "@/lib/budget-constants";

// Lazy-load the map panel so MapLibre GL (~1.1 MB) is split out of the editor's
// first chunk and only fetched when a map-bearing view is actually shown
// (audit 35.2 PERF-001 / LH-PERF-AUTH). `ssr: false`: MapLibre is browser-only.
const MapPanel = dynamic(
  () => import("@/components/Map/MapPanel").then((m) => m.MapPanel),
  { ssr: false, loading: () => null },
);

/**
 * Trip planner — synchronous flow (ADR-043, PR4-front).
 *
 * The 4-step wizard (Saisie → Aperçu → Analyse → Voyage) collapsed into a
 * binary model:
 *   - **loader** while the route is being fetched / the GPX uploaded / the
 *     structural computation runs (no stages yet);
 *   - **trip view** as soon as the structural stages exist.
 *
 * Weather and AI are the only remaining asynchronous enrichments. They render
 * their own spinners on top of the already-displayed trip view, driven by
 * `useUiStore.blockStatus` (hydrated from `/detail`, kept live by Mercure).
 * There is no longer a user gate between structural computation and
 * enrichment.
 */
export function TripPlanner() {
  const t = useTranslations();
  const isOnline = useOfflineStore((s) => s.isOnline);

  const {
    trip,
    isLocked,
    outOfZone,
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
    handleAiGeneration,
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
    handleDeleteTrip,
    handleShareTrip,
    relaunchFullAnalysis,
    isShareModalOpen,
    setShareModalOpen,
    clearNewAccKey,
    pendingModifications,
    isBatchApplying,
    handleApplyBatch,
    handleCancelBatch,
  } = useTripPlanner();

  // Auto-submit when ?link= query param is present
  useLinkParam(handleMagicLink);

  const setConfigPanelOpen = useUiStore((s) => s.setConfigPanelOpen);
  const focusedMapStageIndex = useUiStore((s) => s.focusedMapStageIndex);
  const setFocusedMapStageIndex = useUiStore((s) => s.setFocusedMapStageIndex);
  const viewMode = useUiStore((s) => s.viewMode);
  const setViewMode = useUiStore((s) => s.setViewMode);
  const aiAvailable = useUiStore((s) => s.aiCapability.available);
  const aiConfigured = useUiStore((s) => s.aiCapability.configured);
  const setAiAvailable = useUiStore((s) => s.setAiAvailable);

  // Initialise the AI availability signal on mount. Since ADR-042 there is no
  // self-hosted tier to probe: with the BYO-token cloud model availability
  // resolves to `true` here. A genuine provider outage surfaces reactively via
  // the 503 the chat endpoint returns, not from this mount-time call.
  useEffect(() => {
    if (!isAiFeatureEnabled()) return;
    let cancelled = false;
    void fetchAiAvailability().then((available) => {
      if (!cancelled) setAiAvailable(available);
    });
    return () => {
      cancelled = true;
    };
  }, [setAiAvailable]);

  // Sync the `configured` signal from the account (ADR-042): AI surfaces stay
  // disabled-but-visible until a provider + token is set.
  useAiSettings();

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

  // E2E test hooks. The prod build does not expose `window.__zustand_ui_store`
  // (guarded by NODE_ENV), so the few flags tests need to drive are exposed via
  // CustomEvents instead.
  useEffect(() => {
    const onProcessing = (e: Event) => {
      useUiStore.getState().setProcessing(!!(e as CustomEvent<boolean>).detail);
    };
    const onBlockStatus = (e: Event) => {
      const detail = (
        e as CustomEvent<{
          weather?: import("@/store/ui-store").BlockStatus;
          ai?: import("@/store/ui-store").BlockStatus;
        }>
      ).detail;
      if (!detail) return;
      const ui = useUiStore.getState();
      if (detail.weather !== undefined)
        ui.setBlockStatus("weather", detail.weather);
      if (detail.ai !== undefined) ui.setBlockStatus("ai", detail.ai);
    };
    const onClearAiOverview = () => {
      useTripStore.getState().setAiOverview(null);
    };
    const onSetActiveDayNumber = (e: Event) => {
      const value = (e as CustomEvent<number | null>).detail;
      useUiStore
        .getState()
        .setActiveDayNumber(typeof value === "number" ? value : null);
    };
    const onSetTripId = (e: Event) => {
      const id = (e as CustomEvent<string | null>).detail;
      if (id) {
        useTripStore.getState().setTrip({ id, title: "Test", sourceUrl: "" });
      } else {
        useTripStore.getState().clearTrip();
      }
    };
    const onSetAiCapability = (e: Event) => {
      const detail = (
        e as CustomEvent<{
          available: boolean;
          configured?: boolean;
        }>
      ).detail;
      if (detail) {
        // `configured` defaults to true so the capability tests that only drive
        // `available` keep exercising the active path.
        useUiStore.getState().setAiCapability({
          available: detail.available,
          configured: detail.configured ?? true,
        });
      }
    };
    window.addEventListener("__test_set_ai_capability", onSetAiCapability);
    window.addEventListener("__test_set_processing", onProcessing);
    window.addEventListener("__test_set_block_status", onBlockStatus);
    window.addEventListener("__test_clear_ai_overview", onClearAiOverview);
    window.addEventListener(
      "__test_set_active_day_number",
      onSetActiveDayNumber,
    );
    window.addEventListener("__test_set_trip_id", onSetTripId);
    return () => {
      window.removeEventListener("__test_set_processing", onProcessing);
      window.removeEventListener("__test_set_block_status", onBlockStatus);
      window.removeEventListener("__test_clear_ai_overview", onClearAiOverview);
      window.removeEventListener(
        "__test_set_active_day_number",
        onSetActiveDayNumber,
      );
      window.removeEventListener("__test_set_trip_id", onSetTripId);
      window.removeEventListener("__test_set_ai_capability", onSetAiCapability);
    };
  }, []);

  // E2E test hook: allow Playwright to enqueue modifications directly without
  // going through real UI interactions (accommodation click, distance edit, etc.).
  // This keeps batch-mode tests lean and deterministic.
  useEffect(() => {
    const handler = (e: Event) => {
      const mod = (
        e as CustomEvent<{
          stageIndex: number | null;
          type: "accommodation" | "distance" | "dates" | "pacing";
          label: string;
        }>
      ).detail;
      useTripStore.getState().queueModification(mod);
    };
    window.addEventListener("__test_queue_modification", handler);
    return () =>
      window.removeEventListener("__test_queue_modification", handler);
  }, []);

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

  // Binary UI model (ADR-043, PR4-front):
  //   - `isWelcome`  — no trip and nothing in flight: show the card selection.
  //   - `isLoading`  — the single loader: a trip is being created (route fetch /
  //     GPX upload / structural computation) but no structural stages exist yet.
  //   - trip view    — as soon as structural stages exist, render the full view.
  //     Weather and AI enrichments arrive on top via their own per-block
  //     spinners (no preview / analysis gate).
  const isWelcome = !trip && !isProcessing;
  const isTripLoaded = !!trip && activeStages.length > 0;
  // Single loader: a creation is in flight (route fetch / GPX upload /
  // structural computation) or a trip identity exists but no structural stage
  // has landed yet.
  const isLoading = !isWelcome && !isTripLoaded;

  return (
    <GpxDropZone
      onDrop={handleGpxUpload}
      disabled={isProcessing || !!trip || !isOnline}
    >
      {/* Desktop top bar (#384) — brand, nav tabs, help, language, theme,
          profile. Trip-specific actions now sit next to the trip title
          (recette #649). */}
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

        {/* === State 1: Welcome (no trip, not processing) === */}
        {isWelcome && (
          <div className="flex flex-col items-center justify-center min-h-[60vh] gap-6">
            <CardSelection
              onSubmitUrl={handleMagicLink}
              onUploadFile={handleGpxUpload}
              onLaunchAiGeneration={(brief) => {
                // The chat card consolidates the brief (structured `collected`
                // parameters + the rider's turns as fallback, ADR-045); the
                // host just forwards it to POST /trips/ai-generate.
                if (brief) void handleAiGeneration(brief);
              }}
              disabled={!isOnline}
            />
            <RecentTrips />
          </div>
        )}

        {/* === State 2: Loader (single loader — route fetch / GPX upload /
             synchronous structural computation, before any stage exists). === */}
        {isLoading && (
          <div
            className="flex flex-col items-center justify-center min-h-[60vh] gap-4"
            data-testid="trip-loader"
            aria-busy="true"
          >
            <Loader2 className="h-10 w-10 text-brand animate-spin" />
            <p className="text-sm text-muted-foreground">
              {t("planner.computing")}
            </p>
          </div>
        )}

        {/* === State 3: Trip loaded — full view. Rendered as soon as structural
             stages exist; weather / AI enrichments arrive on top via their own
             per-block spinners (ADR-043). === */}
        {isTripLoaded && (
          <>
            {/* Inline recomputation progress bar — thin bar at top of page */}
            <InlineRecomputationBar />

            {/* Batch modification queue — floating panel at bottom of page */}
            {pendingModifications.length > 0 && (
              <ModificationQueue
                onApply={handleApplyBatch}
                onCancel={handleCancelBatch}
                isApplying={isBatchApplying}
              />
            )}

            {/* Trip title + actions — the per-trip toolbar (downloads,
                undo/redo, share, config) sits on the title line, aligned to
                the right (recette #649). The redundant close button has been
                removed. */}
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0 flex-1 text-center md:text-left">
                <TripHeader
                  title={trip.title}
                  onTitleChange={handleTitleChange}
                  showTitleSuggestion={totalDistance !== null}
                  isTitleLoading={isProcessing && totalDistance === null}
                />
              </div>
              <div className="shrink-0">
                <TripActions
                  tripId={trip.id}
                  tripTitle={trip.title}
                  onShare={() => setShareModalOpen(true)}
                  onOpenConfig={() => setConfigPanelOpen(true)}
                />
              </div>
            </div>

            {/* Locked banner */}
            {isLocked && (
              <div className="mt-4">
                <TripLockedBanner />
              </div>
            )}

            {/* Out-of-zone banner — route outside the provisioned coverage area:
                display-only, no Valhalla rerouting (ADR-040). */}
            {outOfZone && (
              <div className="mt-4">
                <OutOfZoneBanner />
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
                showNoDatesBanner={!isLocked}
              />

              {/* Trip-level AI overview (issue #305) — narrative + patterns +
                  recommendations produced by the LLaMA pass 2. Renders nothing
                  silently when the LLM pipeline is disabled or did not produce
                  an overview. Placed above the stage timeline so it gives the
                  user a high-level view before they dive into per-stage data. */}
              {isAiFeatureEnabled() && (
                <>
                  {!aiConfigured ? (
                    <AiUnavailableNotice
                      variant="notConfigured"
                      context="analysis"
                    />
                  ) : (
                    !aiAvailable && <AiUnavailableNotice context="analysis" />
                  )}
                  <TripAiOverview
                    onRegenerate={() => void relaunchFullAnalysis()}
                  />
                </>
              )}

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
                Carries the view mode toggle so it stays reachable while the
                user is deep in the timeline. The day progress bar is no longer
                duplicated here: it lives above the days (and stays sticky)
                inside the roadbook itself (recette #649). */}
              {hasMap && (
                <div
                  ref={fixedHeaderRef}
                  className={[
                    "fixed top-0 left-0 right-0 z-20 bg-background border-b border-border",
                    "transition-transform duration-200",
                    isScrolledPast
                      ? ""
                      : "-translate-y-full pointer-events-none",
                  ].join(" ")}
                >
                  <div className="max-w-[1200px] mx-auto px-4 md:px-6 py-2 flex justify-end">
                    <ViewModeToggle testId="view-mode-toggle-sticky" />
                  </div>
                </div>
              )}

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
                {/* Master/detail roadbook — sidebar timeline (left) +
                    selected-stage detail panel (right). Hidden in "map" mode
                    when a map is available. */}
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
                    <RoadbookMasterDetail
                      stages={stages}
                      startDate={startDate}
                      isProcessing={isProcessing}
                      readOnly={isLocked || !isOnline || outOfZone}
                      onDeleteStage={handleDeleteStage}
                      onAddStage={handleAddStage}
                      onInsertRestDay={handleInsertRestDay}
                      onDistanceChange={handleDistanceChange}
                      onAddAccommodation={handleAddAccommodation}
                      onUpdateAccommodation={updateLocalAccommodation}
                      onRemoveAccommodation={removeLocalAccommodation}
                      onSelectAccommodation={handleSelectAccommodation}
                      onDeselectAccommodation={handleDeselectAccommodation}
                      onExpandAccommodationRadius={
                        handleExpandAccommodationRadius
                      }
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
          readOnly={isLocked || !isOnline || outOfZone}
          hasTripLoaded={!!trip}
          tripTitle={trip?.title}
          onDuplicate={handleDuplicateTrip}
          onShare={handleShareTrip}
          onDelete={handleDeleteTrip}
        />

        {/* Floating AI assistant — visible as soon as the trip view is rendered
            (no longer gated by an analysis phase). Hidden on the welcome /
            loader screens via its own `trip` guard. */}
        {isAiFeatureEnabled() && <AiBubble />}
      </main>
    </GpxDropZone>
  );
}
