"use client";

import { useCallback, useEffect, useRef } from "react";
import { useTranslations } from "next-intl";
import { StageCard } from "@/components/stage-card";
import { StagePanelSkeleton } from "@/components/stage-panel-skeleton";
import { StageSkeleton } from "@/components/stage-skeleton";
import { RestDayCard } from "@/components/rest-day-card";
import { RoadbookEmptyState } from "@/components/roadbook-empty-state";
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
}: StageDetailPanelProps) {
  const tStage = useTranslations("stage");
  const recomputingStages = useTripStore((s) => s.recomputingStages);
  const setSelectedStageIndex = useTripStore((s) => s.setSelectedStageIndex);
  const containerRef = useRef<HTMLDivElement>(null);
  const selectedRef = useRef<HTMLDivElement>(null);
  const prevSelectedIndexRef = useRef(selectedIndex);
  // Set while a scroll-spy update is in flight so the scroll-into-view effect
  // below ignores it — otherwise the programmatic scroll would fight the user's
  // own scrolling.
  const fromScrollSpyRef = useRef(false);

  // Scroll the selected stage into view on *deliberate* selection changes
  // (clicking a day on the horizontal timeline) — but skip the initial render
  // so loading a trip keeps the scroll at the top instead of jumping down to
  // the first stage (recette #649), and skip selections that originate from the
  // scroll-spy below. Comparing against the previous index (rather than a
  // "has-run" flag) stays correct under React Strict Mode's double-invoked
  // mount effect.
  useEffect(() => {
    if (prevSelectedIndexRef.current === selectedIndex) return;
    prevSelectedIndexRef.current = selectedIndex;
    if (fromScrollSpyRef.current) {
      fromScrollSpyRef.current = false;
      return;
    }
    selectedRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, [selectedIndex]);

  // Scroll-spy: mark a stage active as soon as it reaches the middle of the
  // viewport (~50% of its height) while scrolling (recette #649). An
  // IntersectionObserver with a centred, zero-height root margin defines a thin
  // band at the vertical middle; the topmost section currently crossing that
  // line becomes the selected stage. Visibility is tracked in a Map because the
  // observer callback only reports sections whose intersection *changed* — not
  // every visible one.
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    const sections = Array.from(
      container.querySelectorAll<HTMLElement>("[data-stage-index]"),
    );
    if (sections.length === 0) return;

    const visibleTops = new Map<number, number>();

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          const index = Number(
            (entry.target as HTMLElement).dataset.stageIndex,
          );
          if (entry.isIntersecting) {
            visibleTops.set(index, entry.boundingClientRect.top);
          } else {
            visibleTops.delete(index);
          }
        }
        if (visibleTops.size === 0) return;
        // Topmost section inside the band = the one closest to the anchor line.
        let bestIndex = -1;
        let bestTop = Number.POSITIVE_INFINITY;
        for (const [index, top] of visibleTops) {
          if (top < bestTop) {
            bestTop = top;
            bestIndex = index;
          }
        }
        if (bestIndex < 0 || bestIndex === prevSelectedIndexRef.current) return;
        fromScrollSpyRef.current = true;
        setSelectedStageIndex(bestIndex);
      },
      // Anchor a thin band at ~50% of the viewport height: the stage crossing
      // the vertical middle is the "current" one, so a day activates as soon as
      // it scrolls past the middle of the screen.
      { rootMargin: "-50% 0px -50% 0px", threshold: 0 },
    );

    sections.forEach((section) => observer.observe(section));
    return () => observer.disconnect();
  }, [stages.length, setSelectedStageIndex]);

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
    if (isProcessing) {
      return (
        <div data-testid="stage-detail-loading">
          <StagePanelSkeleton />
        </div>
      );
    }
    return (
      <div data-testid="stage-detail-empty">
        <RoadbookEmptyState />
      </div>
    );
  }

  const safeIndex = Math.min(Math.max(0, selectedIndex), stages.length - 1);

  return (
    <div
      ref={containerRef}
      data-testid="stage-detail-panel"
      className="flex flex-col gap-6"
    >
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
                ? "ring-2 ring-brand/20"
                : "opacity-60 hover:opacity-80",
            ].join(" ")}
          >
            {/* Day heading — anchor preserved for scroll-spy and in-app
                scroll-to-day navigation. */}
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
