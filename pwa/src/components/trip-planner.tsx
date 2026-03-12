"use client";

import { useTranslations } from "next-intl";
import { MagicLinkInput } from "@/components/magic-link-input";
import { GpxUploadButton } from "@/components/gpx-upload-button";
import { TripSummary } from "@/components/trip-summary";
import { AlertsSummaryPanel } from "@/components/alerts-summary-panel";
import { TripHeader } from "@/components/trip-header";
import { PacingSettings } from "@/components/pacing-settings";
import { Timeline } from "@/components/timeline";
import { ThemeToggle } from "@/components/theme-toggle";
import { useTripPlanner } from "@/hooks/use-trip-planner";

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
    ebikeMode,
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
    handleAddAccommodation,
    clearNewAccKey,
  } = useTripPlanner();

  return (
    <main className="max-w-[1200px] mx-auto px-4 md:px-6 py-8 md:py-12 relative">
      {/* Skip link */}
      <a
        href="#timeline"
        className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-background focus:p-2 focus:rounded"
      >
        {t("layout.skipToTimeline")}
      </a>

      {/* Toolbar: magic link + GPX upload + buttons */}
      <div className="flex items-center gap-2">
        <div className="flex-1 min-w-0">
          <MagicLinkInput
            onSubmit={handleMagicLink}
            isProcessing={isProcessing}
            disabled={false}
          />
        </div>
        <GpxUploadButton onUpload={handleGpxUpload} disabled={isProcessing} />
        <ThemeToggle />
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

          {/* Alerts summary: warnings/critical vs suggestions/detections */}
          <AlertsSummaryPanel stages={stages} />

          {/* Header: title + locations + calendar + pacing */}
          <TripHeader
            title={trip.title}
            onTitleChange={updateTitle}
            startDate={startDate}
            endDate={endDate}
            onDatesChange={handleDatesChange}
            showTitleSuggestion={totalDistance !== null}
            isTitleLoading={isProcessing && totalDistance === null}
          >
            <PacingSettings
              fatigueFactor={fatigueFactor}
              elevationPenalty={elevationPenalty}
              ebikeMode={ebikeMode}
              onUpdate={handlePacingChange}
              onEbikeModeChange={handleEbikeModeChange}
            />
          </TripHeader>

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
              newAccKey={newAccKey}
              onClearNewAcc={clearNewAccKey}
            />
          </div>
        </div>
      )}
    </main>
  );
}
