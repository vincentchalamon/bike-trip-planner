"use client";

import { useCallback } from "react";
import { useTranslations } from "next-intl";
import { StageCard } from "@/components/stage-card";
import { StageSkeleton } from "@/components/stage-skeleton";
import { RestDayCard } from "@/components/rest-day-card";
import { NoDatesBanner } from "@/components/no-dates-banner";
import { AddStageButton } from "@/components/add-stage-button";
import { AddRestDayButton } from "@/components/add-rest-day-button";
import { useTripStore } from "@/store/trip-store";
import type { StageData, AccommodationData } from "@/lib/validation/schemas";

const MIN_KM = 5;

interface StageDetailPanelProps {
  stages: StageData[];
  selectedIndex: number;
  startDate: string | null;
  isProcessing?: boolean;
  readOnly?: boolean;
  onDeleteStage: (index: number) => void;
  onAddStage?: (afterIndex: number) => void;
  onInsertRestDay?: (afterIndex: number) => void;
  onDistanceChange?: (index: number, distance: number) => void;
  onAddAccommodation: (stageIndex: number) => void;
  onUpdateAccommodation: (
    stageIndex: number,
    accIndex: number,
    data: Partial<AccommodationData>,
  ) => void;
  onRemoveAccommodation: (stageIndex: number, accIndex: number) => void;
  onSelectAccommodation?: (stageIndex: number, accIndex: number) => void;
  onDeselectAccommodation?: (stageIndex: number) => void;
  onExpandAccommodationRadius?: (
    stageIndex: number,
    currentRadiusKm: number,
  ) => Promise<boolean>;
  onAddPoiWaypoint?: (
    stageIndex: number,
    poiLat: number,
    poiLon: number,
  ) => void;
  onAccommodationHover?: (stageIndex: number, accIndex: number | null) => void;
  newAccKey?: string | null;
  onClearNewAcc?: () => void;
  onOpenConfig?: () => void;
}

function formatDayDate(startDate: string | null, dayNumber: number): string {
  const base = startDate ? new Date(startDate) : new Date();
  const date = new Date(base);
  date.setDate(date.getDate() + dayNumber - 1);
  return date.toLocaleDateString(undefined, {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

/**
 * Right-hand panel of the master/detail roadbook view.
 *
 * Renders the full detail view for `stages[selectedIndex]` — all existing
 * features (stats, alerts, weather, supply timeline, accommodations, events,
 * downloads, etc.) are preserved by delegating to {@link StageCard} or
 * {@link RestDayCard}. The component never owns the data; it is a presentation
 * shell driven by the trip store.
 */
export function StageDetailPanel({
  stages,
  selectedIndex,
  startDate,
  isProcessing,
  readOnly = false,
  onDeleteStage,
  onAddStage,
  onInsertRestDay,
  onDistanceChange,
  onAddAccommodation,
  onUpdateAccommodation,
  onRemoveAccommodation,
  onSelectAccommodation,
  onDeselectAccommodation,
  onExpandAccommodationRadius,
  onAddPoiWaypoint,
  onAccommodationHover,
  newAccKey,
  onClearNewAcc,
  onOpenConfig,
}: StageDetailPanelProps) {
  const t = useTranslations("timeline");
  const tStage = useTranslations("stage");
  const recomputingStages = useTripStore((s) => s.recomputingStages);

  const canInsertStage = useCallback(
    (afterIndex: number): boolean => {
      let remainingDistance = 0;
      for (let i = afterIndex; i < stages.length; i++) {
        remainingDistance += stages[i]?.distance ?? 0;
      }
      const stagesAfterInsertion = stages.length - afterIndex + 1;
      return remainingDistance >= stagesAfterInsertion * MIN_KM;
    },
    [stages],
  );

  if (stages.length === 0) {
    return (
      <div
        className="rounded-xl border border-border bg-card p-6 text-sm text-muted-foreground"
        data-testid="stage-detail-empty"
      >
        {t("noStageSelected")}
      </div>
    );
  }

  // The store action `setSelectedStageIndex` already clamps the value, so a
  // stale render path with an out-of-range index is unlikely. Defensive guard.
  const safeIndex = Math.min(Math.max(0, selectedIndex), stages.length - 1);
  const stage = stages[safeIndex];
  if (!stage) return null;

  return (
    <section
      aria-label={tStage("day", { dayNumber: stage.dayNumber })}
      data-testid="stage-detail-panel"
      data-stage-index={safeIndex}
      className="flex flex-col gap-4"
    >
      {!startDate && onOpenConfig && (
        <NoDatesBanner onOpenConfig={onOpenConfig} />
      )}

      {/* Day heading — anchor preserved so StageProgressBar segments remain
          clickable / scroll-to-able from the sticky header. */}
      <header
        id={`timeline-day-${stage.dayNumber}`}
        className="flex items-baseline justify-between gap-3 scroll-mt-20"
      >
        <h2 className="text-xl md:text-2xl font-semibold text-foreground">
          {tStage("day", { dayNumber: stage.dayNumber })}
        </h2>
        <span className="text-xs md:text-sm text-muted-foreground">
          {formatDayDate(startDate, stage.dayNumber)}
        </span>
      </header>

      {stage.isRestDay ? (
        <RestDayCard
          dayNumber={stage.dayNumber}
          stageIndex={safeIndex}
          canDelete={!readOnly && stages.length > 2}
          onDelete={() => onDeleteStage(safeIndex)}
        />
      ) : recomputingStages.has(safeIndex) ? (
        <StageSkeleton />
      ) : (
        <StageCard
          stage={stage}
          stageIndex={safeIndex}
          isFirst={safeIndex === 0}
          isLast={safeIndex === stages.length - 1}
          canDelete={!readOnly && stages.length > 2}
          isProcessing={isProcessing}
          readOnly={readOnly}
          onDelete={() => onDeleteStage(safeIndex)}
          onDistanceChange={
            !readOnly && onDistanceChange
              ? (d) => onDistanceChange(safeIndex, d)
              : undefined
          }
          onAddAccommodation={() => onAddAccommodation(safeIndex)}
          onUpdateAccommodation={(accIdx, data) =>
            onUpdateAccommodation(safeIndex, accIdx, data)
          }
          onRemoveAccommodation={(accIdx) =>
            onRemoveAccommodation(safeIndex, accIdx)
          }
          onSelectAccommodation={
            !readOnly && onSelectAccommodation
              ? (accIdx) => onSelectAccommodation(safeIndex, accIdx)
              : undefined
          }
          onDeselectAccommodation={
            !readOnly && onDeselectAccommodation
              ? () => onDeselectAccommodation(safeIndex)
              : undefined
          }
          onExpandAccommodationRadius={
            !readOnly && onExpandAccommodationRadius
              ? (r) => onExpandAccommodationRadius(safeIndex, r)
              : undefined
          }
          onAddPoiWaypoint={
            !readOnly && onAddPoiWaypoint
              ? (lat, lon) => onAddPoiWaypoint(safeIndex, lat, lon)
              : undefined
          }
          newAccKey={newAccKey}
          stageOriginalIndex={safeIndex}
          onClearNewAcc={onClearNewAcc}
          onAccommodationHover={
            onAccommodationHover
              ? (accIdx) => onAccommodationHover(safeIndex, accIdx)
              : undefined
          }
        />
      )}

      {/* Footer actions — insert a new stage / rest day right after this one.
          Hidden in read-only mode and on the very last stage (no "next day"). */}
      {!readOnly &&
        safeIndex < stages.length - 1 &&
        (onAddStage || onInsertRestDay) && (
          <div className="flex flex-wrap gap-2">
            {onInsertRestDay &&
              !stage.isRestDay &&
              !stages[safeIndex + 1]?.isRestDay && (
                <AddRestDayButton
                  afterIndex={safeIndex}
                  dayNumber={stage.dayNumber}
                  onClick={() => onInsertRestDay(safeIndex)}
                />
              )}
            {onAddStage && (
              <AddStageButton
                afterIndex={safeIndex}
                onClick={() => onAddStage(safeIndex)}
                disabled={!canInsertStage(safeIndex)}
              />
            )}
          </div>
        )}
    </section>
  );
}
