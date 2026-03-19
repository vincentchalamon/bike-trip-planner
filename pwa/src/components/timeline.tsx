"use client";

import { useCallback, useEffect, useMemo } from "react";
import { useTranslations } from "next-intl";
import { TimelineMarker } from "@/components/timeline-marker";
import { StageCard } from "@/components/stage-card";
import { AddStageButton } from "@/components/add-stage-button";
import { AddRestDayButton } from "@/components/add-rest-day-button";
import { RestDayCard } from "@/components/rest-day-card";
import { Skeleton } from "@/components/ui/skeleton";
import { useUiStore } from "@/store/ui-store";
import type { StageData, AccommodationData } from "@/lib/validation/schemas";

interface TimelineProps {
  stages: StageData[];
  startDate: string | null;
  isProcessing?: boolean;
  onDeleteStage: (index: number) => void;
  onAddStage: (afterIndex: number) => void;
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
  const base = startDate ? new Date(startDate) : new Date();
  const date = new Date(base);
  date.setDate(date.getDate() + dayNumber - 1);
  return date.toLocaleDateString(undefined, {
    weekday: "short",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

interface DayGroup {
  dayNumber: number;
  stages: { stage: StageData; originalIndex: number }[];
}

export function Timeline({
  stages,
  startDate,
  isProcessing,
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
}: TimelineProps) {
  const MIN_KM = 5;
  const tTimeline = useTranslations("timeline");
  const setActiveDayNumber = useUiStore((s) => s.setActiveDayNumber);

  const canInsertStage = useCallback(
    (afterIndex: number): boolean => {
      // Sum remaining distance from afterIndex stage to the end
      let remainingDistance = 0;
      for (let i = afterIndex; i < stages.length; i++) {
        remainingDistance += stages[i]?.distance ?? 0;
      }
      // After insertion: (stages from afterIndex to end) + 1 new stage
      const stagesAfterInsertion = stages.length - afterIndex + 1;
      return remainingDistance >= stagesAfterInsertion * MIN_KM;
    },
    [stages],
  );

  const dayGroups = useMemo(() => {
    const groups: DayGroup[] = [];
    for (let i = 0; i < stages.length; i++) {
      const stage = stages[i]!;
      const lastGroup = groups[groups.length - 1];
      if (lastGroup && lastGroup.dayNumber === stage.dayNumber) {
        lastGroup.stages.push({ stage, originalIndex: i });
      } else {
        groups.push({
          dayNumber: stage.dayNumber,
          stages: [{ stage, originalIndex: i }],
        });
      }
    }
    return groups;
  }, [stages]);

  // Observe which day heading is closest to the top of the viewport while
  // scrolling and update the active day number in the UI store accordingly.
  useEffect(() => {
    if (dayGroups.length === 0) return;

    const dayNumbers = dayGroups.map((g) => g.dayNumber);

    function getActiveDayFromScroll(): number | null {
      // Find the last day heading that has scrolled past the viewport midpoint.
      // Iterating in ascending day order, we keep updating `best` as long as
      // the heading's top is at or above half the viewport height.
      let best: number | null = null;
      const threshold = window.innerHeight / 2;

      for (const dayNumber of dayNumbers) {
        const el = document.getElementById(`timeline-day-${dayNumber}`);
        if (!el) continue;
        const rect = el.getBoundingClientRect();
        if (rect.top <= threshold) {
          best = dayNumber;
        }
      }

      // Fallback: if no heading has scrolled past the threshold yet, use the first
      if (best === null && dayNumbers.length > 0) {
        return dayNumbers[0] ?? null;
      }
      return best;
    }

    function handleScroll() {
      setActiveDayNumber(getActiveDayFromScroll());
    }

    // Set initial active day
    setActiveDayNumber(getActiveDayFromScroll());

    window.addEventListener("scroll", handleScroll, { passive: true });
    return () => {
      window.removeEventListener("scroll", handleScroll);
    };
  }, [dayGroups, setActiveDayNumber]);

  if (stages.length === 0) {
    if (!isProcessing) return null;
    return (
      <div className="relative" aria-label={tTimeline("loadingStages")}>
        <div
          className="absolute left-[7px] top-0 bottom-0 w-0.5 bg-brand/30"
          aria-hidden="true"
        />
        <div className="flex items-start gap-0 mb-4">
          <TimelineMarker />
        </div>
        {[1, 2, 3].map((i) => (
          <div key={i} className="flex items-start mb-4">
            <div className="w-4 shrink-0" aria-hidden="true" />
            <div className="ml-6 md:ml-12 flex-1">
              <Skeleton className="w-full md:max-w-[80%] h-32 rounded-xl" />
            </div>
          </div>
        ))}
        <div className="flex items-start">
          <TimelineMarker />
        </div>
      </div>
    );
  }

  return (
    <div className="relative" role="list" aria-label={tTimeline("tripStages")}>
      {/* Vertical line */}
      <div
        className="absolute left-[7px] top-0 bottom-0 w-0.5 bg-brand"
        aria-hidden="true"
      />

      {/* Start marker */}
      <div className="flex items-start gap-0 mb-4">
        <TimelineMarker />
      </div>

      {dayGroups.map((group, groupIndex) => (
        <div key={group.dayNumber}>
          {/* Day header */}
          <div
            id={`timeline-day-${group.dayNumber}`}
            className="flex items-center mb-4 scroll-mt-16"
          >
            <div className="w-4 shrink-0" aria-hidden="true" />
            <div className="ml-6 md:ml-12">
              <h3 className="text-sm font-semibold text-muted-foreground">
                {formatDayDate(startDate, group.dayNumber)}
              </h3>
            </div>
          </div>

          {/* Stages in this day */}
          {group.stages.map(({ stage, originalIndex }) => (
            <div key={stage.dayNumber + "-" + originalIndex} role="listitem">
              <div className="flex items-start mb-4">
                <div className="w-4 shrink-0" aria-hidden="true" />
                <div className="ml-6 md:ml-12 flex-1 min-w-0">
                  {stage.isRestDay ? (
                    <RestDayCard
                      dayNumber={stage.dayNumber}
                      stageIndex={originalIndex}
                      canDelete={stages.length > 2}
                      onDelete={() => onDeleteStage(originalIndex)}
                    />
                  ) : (
                    <StageCard
                      stage={stage}
                      stageIndex={originalIndex}
                      isFirst={originalIndex === 0}
                      isLast={originalIndex === stages.length - 1}
                      canDelete={stages.length > 2}
                      isProcessing={isProcessing}
                      onDelete={() => onDeleteStage(originalIndex)}
                      onDistanceChange={
                        onDistanceChange
                          ? (d) => onDistanceChange(originalIndex, d)
                          : undefined
                      }
                      onAddAccommodation={() =>
                        onAddAccommodation(originalIndex)
                      }
                      onUpdateAccommodation={(accIdx, data) =>
                        onUpdateAccommodation(originalIndex, accIdx, data)
                      }
                      onRemoveAccommodation={(accIdx) =>
                        onRemoveAccommodation(originalIndex, accIdx)
                      }
                      onSelectAccommodation={
                        onSelectAccommodation
                          ? (accIdx) =>
                              onSelectAccommodation(originalIndex, accIdx)
                          : undefined
                      }
                      onDeselectAccommodation={
                        onDeselectAccommodation
                          ? () => onDeselectAccommodation(originalIndex)
                          : undefined
                      }
                      onExpandAccommodationRadius={
                        onExpandAccommodationRadius
                          ? (r) => onExpandAccommodationRadius(originalIndex, r)
                          : undefined
                      }
                      onAddPoiWaypoint={
                        onAddPoiWaypoint
                          ? (lat, lon) =>
                              onAddPoiWaypoint(originalIndex, lat, lon)
                          : undefined
                      }
                      newAccKey={newAccKey}
                      stageOriginalIndex={originalIndex}
                      onClearNewAcc={onClearNewAcc}
                      onAccommodationHover={
                        onAccommodationHover
                          ? (accIdx) =>
                              onAccommodationHover(originalIndex, accIdx)
                          : undefined
                      }
                    />
                  )}
                </div>
              </div>
            </div>
          ))}

          {/* Add stage / rest day buttons between day groups */}
          {groupIndex < dayGroups.length - 1 && (
            <div className="flex items-center mb-4">
              <TimelineMarker />
              <div className="ml-6 md:ml-12 flex-1 min-w-0 flex flex-wrap gap-2">
                {onInsertRestDay &&
                  !group.stages[group.stages.length - 1]?.stage.isRestDay &&
                  !dayGroups[groupIndex + 1]?.stages[0]?.stage.isRestDay && (
                    <AddRestDayButton
                      afterIndex={
                        group.stages[group.stages.length - 1]!.originalIndex
                      }
                      dayNumber={group.dayNumber}
                      onClick={() =>
                        onInsertRestDay(
                          group.stages[group.stages.length - 1]!.originalIndex,
                        )
                      }
                    />
                  )}
                <AddStageButton
                  afterIndex={
                    group.stages[group.stages.length - 1]!.originalIndex
                  }
                  onClick={() =>
                    onAddStage(
                      group.stages[group.stages.length - 1]!.originalIndex,
                    )
                  }
                  disabled={
                    !canInsertStage(
                      group.stages[group.stages.length - 1]!.originalIndex,
                    )
                  }
                />
              </div>
            </div>
          )}
        </div>
      ))}

      {/* End marker */}
      <div className="flex items-start">
        <TimelineMarker />
      </div>
    </div>
  );
}
