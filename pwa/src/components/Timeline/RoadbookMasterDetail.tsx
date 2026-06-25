"use client";

import { useEffect } from "react";
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
  } = props;

  const selectedStageIndex = useTripStore((s) => s.selectedStageIndex);
  const setActiveDayNumber = useUiStore((s) => s.setActiveDayNumber);

  // Keep the legacy `activeDayNumber` UI flag in sync with the selected stage,
  // so the sticky header continues to highlight the correct day. Scroll changes
  // (scroll-spy) update `selectedStageIndex` directly.
  useEffect(() => {
    const stage = stages[selectedStageIndex];
    setActiveDayNumber(stage?.dayNumber ?? null);
  }, [selectedStageIndex, stages, setActiveDayNumber]);

  return (
    <div className="flex flex-col gap-6" data-testid="roadbook-master-detail">
      {/* Detail panel — full width. */}
      <div className="min-w-0">
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
        />
      </div>
    </div>
  );
}
