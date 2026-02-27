"use client";

import { TimelineMarker } from "@/components/timeline-marker";
import { StageCard } from "@/components/stage-card";
import { AddStageButton } from "@/components/add-stage-button";
import type { StageData, AccommodationData } from "@/lib/validation/schemas";
import type { GeocodeResult } from "@/lib/geocode/client";

interface TimelineProps {
  stages: StageData[];
  onDeleteStage: (index: number) => void;
  onMoveStage: (index: number, direction: "up" | "down") => void;
  onAddStage: (afterIndex: number) => void;
  onStageStartChange: (index: number, result: GeocodeResult) => void;
  onStageEndChange: (index: number, result: GeocodeResult) => void;
  onAddAccommodation: (stageIndex: number) => void;
  onUpdateAccommodation: (
    stageIndex: number,
    accIndex: number,
    data: Partial<AccommodationData>,
  ) => void;
  onRemoveAccommodation: (stageIndex: number, accIndex: number) => void;
}

export function Timeline({
  stages,
  onDeleteStage,
  onMoveStage,
  onAddStage,
  onStageStartChange,
  onStageEndChange,
  onAddAccommodation,
  onUpdateAccommodation,
  onRemoveAccommodation,
}: TimelineProps) {
  if (stages.length === 0) return null;

  return (
    <div className="relative" role="list" aria-label="Trip stages">
      {/* Vertical line */}
      <div
        className="absolute left-[7px] top-0 bottom-0 w-0.5 bg-brand"
        aria-hidden="true"
      />

      {/* Start marker */}
      <div className="flex items-start gap-0 mb-4">
        <TimelineMarker />
      </div>

      {stages.map((stage, index) => (
        <div key={stage.dayNumber} role="listitem" aria-live="polite">
          {/* Stage card */}
          <div className="flex items-start mb-4">
            <div className="w-4 shrink-0" aria-hidden="true" />
            <div className="ml-6 md:ml-12 flex-1">
              <StageCard
                stage={stage}
                stageIndex={index}
                totalStages={stages.length}
                isFirst={index === 0}
                isLast={index === stages.length - 1}
                onDelete={() => onDeleteStage(index)}
                onMoveUp={() => onMoveStage(index, "up")}
                onMoveDown={() => onMoveStage(index, "down")}
                onStartChange={(r) => onStageStartChange(index, r)}
                onEndChange={(r) => onStageEndChange(index, r)}
                onAddAccommodation={() => onAddAccommodation(index)}
                onUpdateAccommodation={(accIdx, data) =>
                  onUpdateAccommodation(index, accIdx, data)
                }
                onRemoveAccommodation={(accIdx) =>
                  onRemoveAccommodation(index, accIdx)
                }
              />
            </div>
          </div>

          {/* Add stage button (not after first or last) */}
          {index > 0 && index < stages.length - 1 && (
            <div className="flex items-center mb-4">
              <TimelineMarker />
              <div className="ml-6 md:ml-12 flex-1">
                <AddStageButton
                  afterIndex={index}
                  onClick={() => onAddStage(index)}
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
