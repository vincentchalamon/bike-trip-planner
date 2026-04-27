"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { X, Pencil, Loader2 } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { StageLocations } from "@/components/stage-locations";
import { StageMetadata } from "@/components/stage-metadata";
import { StageAlerts } from "@/components/stage-alerts";
import { AccommodationPanel } from "@/components/accommodation-panel";
import { EventsPanel } from "@/components/events-panel";
import { StageDownloads } from "@/components/stage-downloads";
import { StageDistanceEditor } from "@/components/stage-distance-editor";
import { DifficultyGauge } from "@/components/difficulty-gauge";
import { DiffHighlight } from "@/components/diff-highlight";
import { SupplyTimeline } from "@/components/SupplyTimeline/SupplyTimeline";
import type { StageData, AccommodationData } from "@/lib/validation/schemas";
import { useTripStore } from "@/store/trip-store";
import { getDifficulty } from "@/lib/constants";
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
  const [editingDistance, setEditingDistance] = useState(false);
  const difficulty = getDifficulty(stage.distance, stage.elevation);

  return (
    <Card
      className="border-border shadow-sm rounded-xl w-full md:max-w-[80%] relative"
      data-testid={`stage-card-${stage.dayNumber}`}
    >
      <CardContent className="p-4 md:p-6">
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

        {/* Action buttons */}
        <div
          className={`absolute top-3 flex gap-0.5 ${readOnly ? "right-3" : "right-10"}`}
        >
          <StageDownloads
            tripId={tripId}
            stageIndex={stageIndex}
            dayNumber={stage.dayNumber}
          />
          {!readOnly && onDistanceChange && (
            <Button
              variant="ghost"
              size="icon"
              className="h-6 w-6 text-muted-icon cursor-pointer"
              onClick={() => setEditingDistance(true)}
              aria-label={t("editDistance")}
              title={t("editDistance")}
            >
              <Pencil className="h-3.5 w-3.5" />
            </Button>
          )}
        </div>

        {/* Locations */}
        <StageLocations
          stageIndex={stageIndex}
          startLabel={stage.startLabel || formatCoords(stage.startPoint)}
          endLabel={stage.endLabel || formatCoords(stage.endPoint)}
        />

        {/* Metadata + difficulty + edit distance */}
        <div className="mt-3 flex items-center gap-3 flex-wrap">
          {editingDistance ? (
            <StageDistanceEditor
              initialDistance={stage.distance}
              onCommit={(km) => {
                onDistanceChange?.(km);
                setEditingDistance(false);
              }}
              onCancel={() => setEditingDistance(false)}
            />
          ) : (
            <>
              <DiffHighlight
                stageIndex={stageIndex}
                field="distance"
                changeLabel={t("diffDistanceChanged")}
              >
                <StageMetadata
                  distance={stage.distance}
                  elevation={stage.elevation}
                  elevationLoss={stage.elevationLoss ?? 0}
                  weather={stage.weather}
                  isProcessing={isProcessing}
                  departureHour={stage.isRestDay ? undefined : departureHour}
                  averageSpeedKmh={stage.isRestDay ? undefined : averageSpeed}
                  endPointLat={
                    stage.isRestDay ? undefined : stage.endPoint.lat
                  }
                  endPointLon={
                    stage.isRestDay ? undefined : stage.endPoint.lon
                  }
                  startDate={stage.isRestDay ? undefined : startDate}
                  stageIndex={stage.isRestDay ? undefined : stageIndex}
                />
              </DiffHighlight>
              {stage.distance !== null && (
                <DifficultyGauge
                  difficulty={difficulty}
                  distance={stage.distance}
                  elevation={stage.elevation ?? 0}
                />
              )}
            </>
          )}
        </div>

        {/* Supply timeline */}
        {stage.supplyTimeline && stage.supplyTimeline.length > 0 && (
          <div className="mt-4">
            <SupplyTimeline
              markers={stage.supplyTimeline}
              stageDistance={stage.distance}
            />
          </div>
        )}

        {/* Alerts */}
        {stage.alerts.length > 0 && (
          <div className="mt-3">
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
          </div>
        )}
        {isProcessing && stage.alerts.length === 0 && (
          <div className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            <span>{t("loadingAlerts")}</span>
          </div>
        )}

        {/* Events */}
        {(stage.events?.length ?? 0) > 0 && (
          <EventsPanel events={stage.events ?? []} />
        )}

        {/* Accommodations */}
        {!isLast && (
          <>
            <Separator className="mt-4 mb-4" />
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
      </CardContent>
    </Card>
  );
}
