"use client";

import { useEffect } from "react";
import { useTranslations } from "next-intl";
import { TimelineSidebar } from "./TimelineSidebar";
import { StageDetailPanel } from "./StageDetailPanel";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import type { StageData, AccommodationData } from "@/lib/validation/schemas";

interface RoadbookMasterDetailProps {
  stages: StageData[];
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

/**
 * Master/detail roadbook layout (sprint 26 — issue #394).
 *
 * Renders a vertical sidebar timeline of stages on the left and the detailed
 * view of the currently-selected stage on the right. Selection state lives in
 * the trip store (`selectedStageIndex`) so it survives navigation between
 * view modes (split, timeline-only) without losing context.
 *
 * Mobile fallback: sidebar collapses above the detail panel and stages are
 * rendered as a horizontally-scrollable list of pills (single-column layout).
 */
export function RoadbookMasterDetail(props: RoadbookMasterDetailProps) {
  const {
    stages,
    startDate,
    isProcessing,
    readOnly,
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
  } = props;

  const t = useTranslations("timeline");
  const selectedStageIndex = useTripStore((s) => s.selectedStageIndex);
  const setSelectedStageIndex = useTripStore((s) => s.setSelectedStageIndex);
  const setActiveDayNumber = useUiStore((s) => s.setActiveDayNumber);

  // Keep the legacy `activeDayNumber` UI flag in sync with the selected stage,
  // so the StageProgressBar / sticky header continue to highlight the correct
  // day even though the timeline no longer scrolls.
  useEffect(() => {
    const stage = stages[selectedStageIndex];
    setActiveDayNumber(stage?.dayNumber ?? null);
  }, [selectedStageIndex, stages, setActiveDayNumber]);

  return (
    <div
      className="flex flex-col gap-6 lg:flex-row lg:gap-8 lg:items-start"
      data-testid="roadbook-master-detail"
    >
      {/* Sidebar — sticky on large screens so the timeline stays in view
          while the user reads through the detail panel. */}
      <aside
        aria-label={t("sidebarLabel")}
        className={[
          "w-full lg:w-[260px] lg:shrink-0",
          "lg:sticky lg:top-4 lg:max-h-[calc(100dvh-2rem)] lg:overflow-y-auto",
          "rounded-xl border border-border bg-card/40 p-3 lg:p-4",
        ].join(" ")}
      >
        <TimelineSidebar
          stages={stages}
          selectedIndex={selectedStageIndex}
          onSelect={setSelectedStageIndex}
          isProcessing={isProcessing}
        />
      </aside>

      {/* Detail panel — flexible width. */}
      <div className="flex-1 min-w-0">
        <StageDetailPanel
          stages={stages}
          selectedIndex={selectedStageIndex}
          startDate={startDate}
          isProcessing={isProcessing}
          readOnly={readOnly}
          onDeleteStage={onDeleteStage}
          onAddStage={onAddStage}
          onInsertRestDay={onInsertRestDay}
          onDistanceChange={onDistanceChange}
          onAddAccommodation={onAddAccommodation}
          onUpdateAccommodation={onUpdateAccommodation}
          onRemoveAccommodation={onRemoveAccommodation}
          onSelectAccommodation={onSelectAccommodation}
          onDeselectAccommodation={onDeselectAccommodation}
          onExpandAccommodationRadius={onExpandAccommodationRadius}
          onAddPoiWaypoint={onAddPoiWaypoint}
          onAccommodationHover={onAccommodationHover}
          newAccKey={newAccKey}
          onClearNewAcc={onClearNewAcc}
          onOpenConfig={onOpenConfig}
        />
      </div>
    </div>
  );
}
