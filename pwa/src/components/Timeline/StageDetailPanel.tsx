"use client";

import { useCallback, useEffect, useRef } from "react";
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
  const base = startDate ? new Date(`${startDate}T00:00:00`) : new Date();
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
 * Renders ALL stages in a scrollable list and scrolls the selected stage into
 * view when `selectedIndex` changes. All stage-card-N test IDs remain in the
 * DOM, preserving backward compatibility with existing E2E tests.
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
  const selectedRef = useRef<HTMLDivElement>(null);

  // Scroll the selected stage into view when selection changes.
  useEffect(() => {
    selectedRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, [selectedIndex]);

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

  const safeIndex = Math.min(Math.max(0, selectedIndex), stages.length - 1);

  return (
    <div data-testid="stage-detail-panel" className="flex flex-col gap-6">
      {!startDate && onOpenConfig && (
        <NoDatesBanner onOpenConfig={onOpenConfig} />
      )}

      {stages.map((stage, i) => {
        if (!stage) return null;
        const isSelected = i === safeIndex;

        return (
          <section
            key={`stage-detail-${i}`}
            ref={isSelected ? selectedRef : undefined}
            aria-label={tStage("day", { dayNumber: stage.dayNumber })}
            data-stage-index={i}
            className={[
              "flex flex-col gap-4 rounded-xl p-1 transition-colors",
              isSelected
                ? "ring-2 ring-brand/40 ring-offset-2"
                : "opacity-60 hover:opacity-80",
            ].join(" ")}
          >
            {/* Day heading — anchor preserved so StageProgressBar segments
                remain clickable / scroll-to-able from the sticky header. */}
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
                stageIndex={i}
                canDelete={!readOnly && stages.length > 2}
                onDelete={() => onDeleteStage(i)}
              />
            ) : recomputingStages.has(i) ? (
              <StageSkeleton />
            ) : (
              <StageCard
                stage={stage}
                stageIndex={i}
                isFirst={i === 0}
                isLast={i === stages.length - 1}
                canDelete={!readOnly && stages.length > 2}
                isProcessing={isProcessing}
                readOnly={readOnly}
                onDelete={() => onDeleteStage(i)}
                onDistanceChange={
                  !readOnly && onDistanceChange
                    ? (d) => onDistanceChange(i, d)
                    : undefined
                }
                onAddAccommodation={() => onAddAccommodation(i)}
                onUpdateAccommodation={(accIdx, data) =>
                  onUpdateAccommodation(i, accIdx, data)
                }
                onRemoveAccommodation={(accIdx) =>
                  onRemoveAccommodation(i, accIdx)
                }
                onSelectAccommodation={
                  !readOnly && onSelectAccommodation
                    ? (accIdx) => onSelectAccommodation(i, accIdx)
                    : undefined
                }
                onDeselectAccommodation={
                  !readOnly && onDeselectAccommodation
                    ? () => onDeselectAccommodation(i)
                    : undefined
                }
                onExpandAccommodationRadius={
                  !readOnly && onExpandAccommodationRadius
                    ? (r) => onExpandAccommodationRadius(i, r)
                    : undefined
                }
                onAddPoiWaypoint={
                  !readOnly && onAddPoiWaypoint
                    ? (lat, lon) => onAddPoiWaypoint(i, lat, lon)
                    : undefined
                }
                newAccKey={newAccKey}
                stageOriginalIndex={i}
                onClearNewAcc={onClearNewAcc}
                onAccommodationHover={
                  onAccommodationHover
                    ? (accIdx) => onAccommodationHover(i, accIdx)
                    : undefined
                }
              />
            )}

            {/* Footer actions — insert after this stage. */}
            {!readOnly &&
              i < stages.length - 1 &&
              (onAddStage || onInsertRestDay) && (
                <div className="flex flex-wrap gap-2">
                  {onInsertRestDay &&
                    !stage.isRestDay &&
                    !stages[i + 1]?.isRestDay && (
                      <AddRestDayButton
                        afterIndex={i}
                        dayNumber={stage.dayNumber}
                        onClick={() => onInsertRestDay(i)}
                      />
                    )}
                  {onAddStage && (
                    <AddStageButton
                      afterIndex={i}
                      onClick={() => onAddStage(i)}
                      disabled={!canInsertStage(i)}
                    />
                  )}
                </div>
              )}
          </section>
        );
      })}
    </div>
  );
}
