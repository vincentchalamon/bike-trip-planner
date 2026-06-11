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

  // Scroll-spy: mark a stage active as soon as it reaches the top of the
  // viewport while scrolling (recette #649). An IntersectionObserver with a
  // top-anchored root margin reports the day section crossing the sticky-header
  // line; the closest one above that line becomes the selected stage.
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    const sections = Array.from(
      container.querySelectorAll<HTMLElement>("[data-stage-index]"),
    );
    if (sections.length === 0) return;

    const observer = new IntersectionObserver(
      (entries) => {
        // Pick the entry whose top is closest to (but past) the anchor line.
        let best: { index: number; top: number } | null = null;
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          const index = Number(
            (entry.target as HTMLElement).dataset.stageIndex,
          );
          const top = entry.boundingClientRect.top;
          if (best === null || top > best.top) best = { index, top };
        }
        if (best === null) return;
        if (best.index === prevSelectedIndexRef.current) return;
        fromScrollSpyRef.current = true;
        setSelectedStageIndex(best.index);
      },
      // Anchor near the top of the viewport (below the sticky header) so the
      // stage occupying that band is the "current" one.
      { rootMargin: "-96px 0px -65% 0px", threshold: 0 },
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
                ? "ring-2 ring-brand/20 ring-offset-2"
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
