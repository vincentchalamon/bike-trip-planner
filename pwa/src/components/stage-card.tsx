"use client";

import { useTranslations } from "next-intl";
import { X, Loader2 } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { StageLocations } from "@/components/stage-locations";
import { StageAlerts } from "@/components/stage-alerts";
import { AccommodationPanel } from "@/components/accommodation-panel";
import { EventsPanel } from "@/components/events-panel";
import { StageDownloads } from "@/components/stage-downloads";
import { DiffHighlight } from "@/components/diff-highlight";
import { SupplyTimeline } from "@/components/SupplyTimeline/SupplyTimeline";
import {
  StageAiSummary,
  StageStatsRow,
  StageDifficultyComposed,
  StageWeatherCard,
} from "@/components/StageDetail";
import type { StageData, AccommodationData } from "@/lib/validation/schemas";
import { useTripStore } from "@/store/trip-store";
import { DEFAULT_ACCOMMODATION_RADIUS_KM } from "@/lib/accommodation-constants";

function formatCoords(point: { lat: number; lon: number }): string {
  const latDir = point.lat >= 0 ? "N" : "S";
  const lonDir = point.lon >= 0 ? "E" : "W";
  return `${Math.abs(point.lat).toFixed(3)}°${latDir}, ${Math.abs(point.lon).toFixed(3)}°${lonDir}`;
}

interface StageCardProps {
  stage: StageData;
  stageIndex: number;
  isFirst: boolean;
  isLast: boolean;
  canDelete: boolean;
  onDelete: () => void;
  onDistanceChange?: (distance: number) => void;
  isProcessing?: boolean;
  /** When true, all edit controls are hidden (trip is locked). */
  readOnly?: boolean;
  onAddAccommodation: () => void;
  onUpdateAccommodation: (
    accIndex: number,
    data: Partial<AccommodationData>,
  ) => void;
  onRemoveAccommodation: (accIndex: number) => void;
  onSelectAccommodation?: (accIndex: number) => void;
  onDeselectAccommodation?: () => void;
  onExpandAccommodationRadius?: (currentRadiusKm: number) => Promise<boolean>;
  onAddPoiWaypoint?: (poiLat: number, poiLon: number) => void;
  newAccKey?: string | null;
  stageOriginalIndex?: number;
  onClearNewAcc?: () => void;
  onAccommodationHover?: (accIndex: number | null) => void;
}

/**
 * Right-hand stage detail card.
 *
 * Block order (sprint 26 — issue #395):
 *   1. Locations (departure → arrival)
 *   2. AI summary (Fraunces italic, sparkle, collapsible if long)
 *   3. Stats 4-col (distance editable / D+ / duration / budget)
 *   4. Composed difficulty gauge (physical / technical / elevation)
 *   5. Enriched weather card (forecast + sunrise/sunset)
 *   6. Alerts — collapsible by severity
 *   7. Events — collapsible
 *   8. Accommodations (inline with selector)
 *   9. Supply timeline
 *
 * Functionality of each block is preserved end-to-end; this component only
 * orchestrates the new ordering and wires the inline distance editor through
 * the stats row.
 */
export function StageCard({
  stage,
  stageIndex,
  isFirst,
  isLast,
  canDelete,
  onDelete,
  onDistanceChange,
  isProcessing,
  readOnly = false,
  onAddAccommodation,
  onUpdateAccommodation,
  onRemoveAccommodation,
  onSelectAccommodation,
  onDeselectAccommodation,
  onExpandAccommodationRadius,
  onAddPoiWaypoint,
  newAccKey,
  stageOriginalIndex,
  onClearNewAcc,
  onAccommodationHover,
}: StageCardProps) {
  const t = useTranslations("stage");
  const tripId = useTripStore((s) => s.trip?.id);
  const departureHour = useTripStore((s) => s.departureHour);
  const averageSpeed = useTripStore((s) => s.averageSpeed);
  const startDate = useTripStore((s) => s.startDate);

  const aiSummary = stage.aiSummary?.trim();
  const hasAlerts = stage.alerts.length > 0;

  return (
    <Card
      className="border-border shadow-sm rounded-xl w-full md:max-w-[80%] relative"
      data-testid={`stage-card-${stage.dayNumber}`}
    >
      <CardContent className="p-4 md:p-6 space-y-4">
        {/* Close button — hidden in read-only mode */}
        {!readOnly && (
          <Button
            variant="ghost"
            size="icon"
            className="absolute top-3 right-3 h-6 w-6 text-muted-icon cursor-pointer"
            onClick={onDelete}
            disabled={!canDelete}
            title={
              !canDelete
                ? t("minStagesReached")
                : t("deleteStage", { dayNumber: stage.dayNumber })
            }
            aria-label={t("deleteStage", { dayNumber: stage.dayNumber })}
            data-testid={`delete-stage-${stage.dayNumber}`}
          >
            <X className="h-4 w-4" />
          </Button>
        )}

        {/* Action buttons (downloads) */}
        <div
          className={`absolute top-3 flex gap-0.5 ${readOnly ? "right-3" : "right-10"}`}
        >
          <StageDownloads
            tripId={tripId}
            stageIndex={stageIndex}
            dayNumber={stage.dayNumber}
          />
        </div>

        {/* 1. Locations */}
        <StageLocations
          stageIndex={stageIndex}
          startLabel={stage.startLabel || formatCoords(stage.startPoint)}
          endLabel={stage.endLabel || formatCoords(stage.endPoint)}
        />

        {/* 2. AI summary — only when the backend provided one */}
        {aiSummary && aiSummary.length > 0 && (
          <StageAiSummary summary={aiSummary} />
        )}

        {/* 3. Stats 4-col — distance (editable), D+, duration, budget */}
        <StageStatsRow
          stage={stage}
          stageIndex={stageIndex}
          isFirst={isFirst}
          isLast={isLast}
          isProcessing={isProcessing}
          readOnly={readOnly}
          onDistanceChange={onDistanceChange}
          departureHour={stage.isRestDay ? undefined : departureHour}
          averageSpeedKmh={stage.isRestDay ? undefined : averageSpeed}
        />

        {/* 4. Composed difficulty gauge */}
        {stage.distance !== null && (
          <StageDifficultyComposed
            distance={stage.distance}
            elevation={stage.elevation ?? 0}
          />
        )}

        {/* 5. Enriched weather card */}
        <StageWeatherCard
          weather={stage.weather}
          startDate={startDate}
          stageIndex={stageIndex}
          endPointLat={stage.isRestDay ? undefined : stage.endPoint.lat}
          endPointLon={stage.isRestDay ? undefined : stage.endPoint.lon}
        />

        {/* 6. Alerts — collapsible by severity (already implemented) */}
        {hasAlerts && (
          <DiffHighlight
            stageIndex={stageIndex}
            field="alerts_added"
            changeLabel={t("diffAlertsAdded")}
          >
            <StageAlerts
              alerts={stage.alerts}
              onAddPoiWaypoint={onAddPoiWaypoint}
            />
          </DiffHighlight>
        )}
        {!hasAlerts && isProcessing && (
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            <span>{t("loadingAlerts")}</span>
          </div>
        )}

        {/* 7. Events — collapsible (already implemented in EventsPanel) */}
        {(stage.events?.length ?? 0) > 0 && (
          <EventsPanel events={stage.events ?? []} />
        )}

        {/* 8. Accommodations — inline with selector */}
        {!isLast && (
          <>
            <Separator />
            <AccommodationPanel
              accommodations={stage.accommodations}
              selectedAccommodation={stage.selectedAccommodation}
              onUpdate={onUpdateAccommodation}
              onRemove={onRemoveAccommodation}
              onAdd={onAddAccommodation}
              onSelect={onSelectAccommodation}
              onDeselect={onDeselectAccommodation}
              onExpandRadius={onExpandAccommodationRadius}
              newAccKey={newAccKey}
              stageIndex={stageOriginalIndex}
              onClearNewAcc={onClearNewAcc}
              searchRadiusKm={
                stage.accommodationSearchRadiusKm ??
                DEFAULT_ACCOMMODATION_RADIUS_KM
              }
              onAccommodationHover={onAccommodationHover}
              readOnly={readOnly}
            />
          </>
        )}

        {/* 9. Supply timeline */}
        {stage.supplyTimeline && stage.supplyTimeline.length > 0 && (
          <SupplyTimeline
            markers={stage.supplyTimeline}
            stageDistance={stage.distance}
          />
        )}
      </CardContent>
    </Card>
  );
}
