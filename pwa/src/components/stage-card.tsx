"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { X, Download, Loader2, Pencil, Check } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Separator } from "@/components/ui/separator";
import { StageLocations } from "@/components/stage-locations";
import { StageMetadata } from "@/components/stage-metadata";
import { AlertList } from "@/components/alert-list";
import { AccommodationPanel } from "@/components/accommodation-panel";
import type { StageData, AccommodationData } from "@/lib/validation/schemas";

function formatCoords(point: { lat: number; lon: number }): string {
  const latDir = point.lat >= 0 ? "N" : "S";
  const lonDir = point.lon >= 0 ? "E" : "W";
  return `${Math.abs(point.lat).toFixed(3)}°${latDir}, ${Math.abs(point.lon).toFixed(3)}°${lonDir}`;
}

function getDifficulty(
  distance: number | null,
  elevation: number | null,
): "easy" | "medium" | "hard" {
  const d = distance ?? 0;
  const e = elevation ?? 0;
  if (d < 60 && e < 800) return "easy";
  if (d < 100 && e < 1500) return "medium";
  return "hard";
}

const difficultyColors = {
  easy: "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400",
  medium:
    "bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400",
  hard: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
} as const;

interface StageCardProps {
  stage: StageData;
  stageIndex: number;
  isFirst: boolean;
  isLast: boolean;
  canDelete: boolean;
  onDelete: () => void;
  onDistanceChange?: (distance: number) => void;
  isProcessing?: boolean;
  onAddAccommodation: () => void;
  onUpdateAccommodation: (
    accIndex: number,
    data: Partial<AccommodationData>,
  ) => void;
  onRemoveAccommodation: (accIndex: number) => void;
  newAccKey?: string | null;
  stageOriginalIndex?: number;
  onClearNewAcc?: () => void;
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
  onAddAccommodation,
  onUpdateAccommodation,
  onRemoveAccommodation,
  newAccKey,
  stageOriginalIndex,
  onClearNewAcc,
}: StageCardProps) {
  const t = useTranslations("stage");
  const [editingDistance, setEditingDistance] = useState(false);
  const [editValue, setEditValue] = useState("");

  function startEditDistance() {
    setEditValue(
      stage.distance !== null ? String(Math.round(stage.distance)) : "",
    );
    setEditingDistance(true);
  }

  function commitDistance() {
    const km = parseFloat(editValue);
    if (!isNaN(km) && km > 0 && onDistanceChange) {
      onDistanceChange(km);
    }
    setEditingDistance(false);
  }

  function handleDistanceKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter") {
      commitDistance();
    } else if (e.key === "Escape") {
      setEditingDistance(false);
    }
  }

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
          title={
            !canDelete
              ? t("minStagesReached" as Parameters<typeof t>[0])
              : undefined
          }
          aria-label={t("deleteStage", { dayNumber: stage.dayNumber })}
          data-testid={`delete-stage-${stage.dayNumber}`}
        >
          <X className="h-4 w-4" />
        </Button>

        {/* Action buttons */}
        <div className="absolute top-3 right-10 flex gap-0.5">
          <Button
            variant="ghost"
            size="sm"
            className={`h-6 gap-1 text-muted-icon px-1.5 ${stage.gpxContent ? "cursor-pointer" : ""}`}
            disabled={!stage.gpxContent}
            onClick={() => {
              if (!stage.gpxContent) return;
              const blob = new Blob([stage.gpxContent], {
                type: "application/gpx+xml",
              });
              const url = URL.createObjectURL(blob);
              const a = document.createElement("a");
              a.href = url;
              a.download = `stage-${stage.dayNumber}.gpx`;
              document.body.appendChild(a);
              a.click();
              document.body.removeChild(a);
              URL.revokeObjectURL(url);
            }}
            aria-label={t("downloadGpx", { dayNumber: stage.dayNumber })}
            title={t("downloadGpx", { dayNumber: stage.dayNumber })}
          >
            <Download className="h-3.5 w-3.5" />
            <span className="text-[10px] font-medium">GPX</span>
          </Button>
          {onDistanceChange && (
            <Button
              variant="ghost"
              size="icon"
              className="h-6 w-6 text-muted-icon cursor-pointer"
              onClick={startEditDistance}
              aria-label={t("editDistance" as Parameters<typeof t>[0])}
              title={t("editDistance" as Parameters<typeof t>[0])}
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
            <div className="flex items-center gap-2">
              <Input
                type="number"
                value={editValue}
                onChange={(e) => setEditValue(e.target.value)}
                onKeyDown={handleDistanceKeyDown}
                className="h-7 w-20 text-sm"
                min={1}
                aria-label={t("distanceLabel" as Parameters<typeof t>[0])}
                autoFocus
              />
              <span className="text-sm text-muted-foreground">km</span>
              <Button
                variant="outline"
                size="icon"
                className="h-7 w-7 cursor-pointer"
                onClick={commitDistance}
                aria-label={t("saveDistance" as Parameters<typeof t>[0])}
              >
                <Check className="h-3.5 w-3.5" />
              </Button>
            </div>
          ) : (
            <>
              <StageMetadata
                distance={stage.distance}
                elevation={stage.elevation}
                elevationLoss={stage.elevationLoss ?? 0}
                weather={stage.weather}
                isProcessing={isProcessing}
              />
              {stage.distance !== null && (
                <span
                  className={`text-xs font-medium px-2 py-0.5 rounded-full ${difficultyColors[getDifficulty(stage.distance, stage.elevation)]}`}
                >
                  {t(
                    `difficulty${getDifficulty(stage.distance, stage.elevation).charAt(0).toUpperCase()}${getDifficulty(stage.distance, stage.elevation).slice(1)}` as Parameters<
                      typeof t
                    >[0],
                  )}
                </span>
              )}
            </>
          )}
        </div>

        {/* Alerts */}
        {stage.alerts.length > 0 && (
          <div className="mt-3">
            <AlertList alerts={stage.alerts} />
          </div>
        )}
        {isProcessing && stage.alerts.length === 0 && (
          <div className="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            <span>{t("loadingAlerts" as Parameters<typeof t>[0])}</span>
          </div>
        )}

        {/* Accommodations */}
        {!isLast && (
          <>
            <Separator className="mt-4 mb-4" />
            <AccommodationPanel
              accommodations={stage.accommodations}
              onUpdate={onUpdateAccommodation}
              onRemove={onRemoveAccommodation}
              onAdd={onAddAccommodation}
              newAccKey={newAccKey}
              stageIndex={stageOriginalIndex}
              onClearNewAcc={onClearNewAcc}
              isProcessing={isProcessing}
            />
          </>
        )}
      </CardContent>
    </Card>
  );
}
