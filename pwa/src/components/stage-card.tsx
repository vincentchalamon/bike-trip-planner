"use client";

import { X, ChevronUp, ChevronDown } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { StageLocations } from "@/components/stage-locations";
import { StageMetadata } from "@/components/stage-metadata";
import { AlertList } from "@/components/alert-list";
import { AccommodationPanel } from "@/components/accommodation-panel";
import type { StageData, AccommodationData } from "@/lib/validation/schemas";
import type { GeocodeResult } from "@/lib/geocode/client";

interface StageCardProps {
  stage: StageData;
  stageIndex: number;
  totalStages: number;
  isFirst: boolean;
  isLast: boolean;
  onDelete: () => void;
  onMoveUp: () => void;
  onMoveDown: () => void;
  onStartChange: (result: GeocodeResult) => void;
  onEndChange: (result: GeocodeResult) => void;
  onAddAccommodation: () => void;
  onUpdateAccommodation: (
    accIndex: number,
    data: Partial<AccommodationData>,
  ) => void;
  onRemoveAccommodation: (accIndex: number) => void;
}

export function StageCard({
  stage,
  stageIndex,
  totalStages,
  isFirst,
  isLast,
  onDelete,
  onMoveUp,
  onMoveDown,
  onStartChange,
  onEndChange,
  onAddAccommodation,
  onUpdateAccommodation,
  onRemoveAccommodation,
}: StageCardProps) {
  const canDelete = totalStages > 2;

  return (
    <Card
      className="border-border shadow-sm rounded-xl w-full md:max-w-[80%] relative"
      data-testid={`stage-card-${stage.dayNumber}`}
    >
      <CardContent className="p-4 md:p-6">
        {/* Close button */}
        <Button
          variant="ghost"
          size="icon"
          className="absolute top-3 right-3 h-6 w-6 text-muted-icon"
          onClick={onDelete}
          disabled={!canDelete}
          aria-label={`Delete stage ${stage.dayNumber}`}
          data-testid={`delete-stage-${stage.dayNumber}`}
        >
          <X className="h-4 w-4" />
        </Button>

        {/* Move buttons */}
        <div className="absolute top-3 right-10 flex gap-0.5">
          <Button
            variant="ghost"
            size="icon"
            className="h-6 w-6 text-muted-icon"
            onClick={onMoveUp}
            disabled={isFirst}
            aria-label={`Move stage ${stage.dayNumber} up`}
          >
            <ChevronUp className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            className="h-6 w-6 text-muted-icon"
            onClick={onMoveDown}
            disabled={isLast}
            aria-label={`Move stage ${stage.dayNumber} down`}
          >
            <ChevronDown className="h-4 w-4" />
          </Button>
        </div>

        {/* Day number */}
        <div className="text-xs font-medium text-muted-foreground mb-2">
          Day {stage.dayNumber}
        </div>

        {/* Locations */}
        <StageLocations
          stageIndex={stageIndex}
          startLabel={stage.startLabel ?? ""}
          endLabel={stage.endLabel ?? ""}
          onStartChange={onStartChange}
          onEndChange={onEndChange}
        />

        {/* Metadata */}
        <div className="mt-3">
          <StageMetadata
            distance={stage.distance}
            elevation={stage.elevation}
            weather={stage.weather}
          />
        </div>

        {/* Alerts */}
        {stage.alerts.length > 0 && (
          <div className="mt-3">
            <AlertList alerts={stage.alerts} />
          </div>
        )}

        {/* Accommodations */}
        {!isFirst && !isLast && (
          <>
            <Separator className="mt-4 mb-4" />
            <AccommodationPanel
              accommodations={stage.accommodations}
              onUpdate={onUpdateAccommodation}
              onRemove={onRemoveAccommodation}
              onAdd={onAddAccommodation}
            />
          </>
        )}
      </CardContent>
    </Card>
  );
}
