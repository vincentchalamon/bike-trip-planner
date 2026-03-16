"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Settings } from "lucide-react";
import { MagicLinkInput } from "@/components/magic-link-input";
import { GpxUploadButton } from "@/components/gpx-upload-button";
import { TripSummary } from "@/components/trip-summary";
import { TripHeader } from "@/components/trip-header";
import { StageProgressBar } from "@/components/stage-progress-bar";
import { Timeline } from "@/components/timeline";
import { ConfigPanel } from "@/components/config-panel";
import { Button } from "@/components/ui/button";
import { useTripPlanner } from "@/hooks/use-trip-planner";
import { useUiStore } from "@/store/ui-store";

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
    handleEbikeModeChange,
    handleDepartureHourChange,
    handleAddAccommodation,
    handleSelectAccommodation,
    handleDeselectAccommodation,
    handleExpandAccommodationRadius,
    handleInsertRestDay,
    clearNewAccKey,
  } = useTripPlanner();

  const setConfigPanelOpen = useUiStore((s) => s.setConfigPanelOpen);

  // Show the sticky progress bar only when its natural position has scrolled
  // off the top of the viewport. An IntersectionObserver watches an invisible
  // sentinel div placed where the bar would normally sit.
  const sentinelRef = useRef<HTMLDivElement>(null);
  const [isScrolledPast, setIsScrolledPast] = useState(false);
  const hasTripData = !!trip;
  const scrollDirRef = useRef<"down" | "up">("down");
  const lastScrollYRef = useRef(0);

  // Track scroll direction so the bar is only hidden when scrolling back up.
  useEffect(() => {
    function onScroll() {
      const y = window.scrollY;
      scrollDirRef.current = y >= lastScrollYRef.current ? "down" : "up";
      lastScrollYRef.current = y;
    }
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  useEffect(() => {
    const sentinel = sentinelRef.current;
    if (!sentinel) return;
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry?.isIntersecting) {
          setIsScrolledPast(true);
        } else if (scrollDirRef.current === "up") {
          setIsScrolledPast(false);
        }
      },
      { threshold: 0 },
    );
    observer.observe(sentinel);
    return () => observer.disconnect();
  }, [hasTripData]);

  return (
    <main className="max-w-[1200px] mx-auto px-4 md:px-6 py-8 md:py-12 relative">
      {/* Skip link */}
      <a
        href="#timeline"
        className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-background focus:p-2 focus:rounded"
      >
        {t("layout.skipToTimeline")}
      </a>

      {/* Toolbar: magic link + GPX upload + config button */}
      <div className="flex items-center gap-2">
        <div className="flex-1 min-w-0">
          <MagicLinkInput
            onSubmit={handleMagicLink}
            isProcessing={isProcessing}
            disabled={false}
          />
        </div>
        <GpxUploadButton onUpload={handleGpxUpload} disabled={isProcessing} />
        <Button
          variant="ghost"
          size="icon"
          className="h-9 w-9 cursor-pointer"
          onClick={() => setConfigPanelOpen(true)}
          title={t("config.open")}
          aria-label={t("config.open")}
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
          />

          {/* Sentinel — marks the natural position of the progress bar in the
              flow. The sticky bar becomes visible once this exits the viewport. */}
          <div ref={sentinelRef} aria-hidden="true" />

          {/* Segmented progress bar — fixed, visible only after scrolling past
              the sentinel so it does not duplicate the timeline start.
              Fixed positioning avoids the sticky-layout glitch where the bar
              briefly appears mid-content before snapping to the top. */}
          <div
            className={[
              "fixed top-0 left-0 right-0 z-20 bg-background overflow-hidden",
              "transition-transform duration-200",
              isScrolledPast ? "" : "-translate-y-full pointer-events-none",
            ].join(" ")}
          >
            <div className="max-w-[1200px] mx-auto px-4 md:px-6 py-2">
              <StageProgressBar />
            </div>
          </div>

          {/* Timeline */}
          <div id="timeline">
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
              newAccKey={newAccKey}
              onClearNewAcc={clearNewAccKey}
            />
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
        onEbikeModeChange={handleEbikeModeChange}
        onDepartureHourChange={handleDepartureHourChange}
        onAccommodationTypesChange={handleAccommodationTypesChange}
      />
    </main>
  );
}
