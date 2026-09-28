"use client";

import { StageDetailPanel } from "./StageDetailPanel";
import { useTripStore } from "@/store/trip-store";
import type { StageData } from "@btp/core";

interface RoadbookMasterDetailProps {
  stages: StageData[];
  startDate: string | null;
  isProcessing?: boolean;
  readOnly?: boolean;
  onAccommodationHover?: (stageIndex: number, accIndex: number | null) => void;
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
export function RoadbookMasterDetail({
  stages,
  startDate,
  isProcessing,
  readOnly,
  onAccommodationHover,
}: RoadbookMasterDetailProps) {
  const selectedStageIndex = useTripStore((s) => s.selectedStageIndex);

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
          onAccommodationHover={onAccommodationHover}
        />
      </div>
    </div>
  );
}
