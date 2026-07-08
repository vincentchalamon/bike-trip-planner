"use client";

import { useState, type ReactNode } from "react";
import { useTranslations } from "next-intl";
import {
  Bike,
  Mountain,
  ArrowUp,
  ArrowDown,
  Clock,
  Wallet,
  Pencil,
  Info,
} from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { Button } from "@/components/ui/button";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { StageDistanceEditor } from "@/components/stage-distance-editor";
import { DiffHighlight } from "@/components/diff-highlight";
import { computeStageTimes, formatDecimalHour } from "@/lib/travel-time";
import {
  MEAL_COST_MIN,
  MEAL_COST_MAX,
  mealsForStage,
} from "@/lib/budget-constants";
import type { StageData } from "@/lib/validation/schemas";

interface StatCellProps {
  label: string;
  icon: ReactNode;
  value: ReactNode;
  /** Optional secondary line rendered below the primary value. */
  hint?: ReactNode;
  /** Optional trailing slot (e.g. inline "edit" button). */
  trailing?: ReactNode;
  testId?: string;
}

function StatCell({
  label,
  icon,
  value,
  hint,
  trailing,
  testId,
}: StatCellProps) {
  // `relative` establishes a stacking context so a `trailing` slot
  // (e.g. the inline pencil edit button on the distance cell) sits above
  // sibling stat cells. Without it, Playwright's hit-test lands on
  // neighbouring cards (`stat-duration`, `stat-elevation`, …) when the
  // viewport places the button near a card edge, causing 30s click timeouts.
  return (
    <div
      className="relative flex flex-col gap-1 rounded-lg border border-border/60 bg-card/40 p-3"
      data-testid={testId}
    >
      <div className="flex items-center justify-between gap-1.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
        <span className="flex items-center gap-1.5">
          <span aria-hidden="true" className="text-muted-foreground">
            {icon}
          </span>
          <span className="truncate">{label}</span>
        </span>
        {trailing && <span className="relative z-10">{trailing}</span>}
      </div>
      <div className="text-base md:text-lg font-semibold text-foreground tabular-nums">
        {value}
      </div>
      {hint && (
        <div className="text-xs text-muted-foreground tabular-nums">{hint}</div>
      )}
    </div>
  );
}

interface StageStatsRowProps {
  stage: StageData;
  stageIndex: number;
  isFirst: boolean;
  isLast: boolean;
  isProcessing?: boolean;
  readOnly?: boolean;
  onDistanceChange?: (distance: number) => void;
  /** Departure hour from trip configuration (used to derive arrival/duration). */
  departureHour?: number;
  /** Average riding speed (km/h) from trip configuration. */
  averageSpeedKmh?: number;
  /**
   * Optional extra cell appended to the grid (recette #649) — used in the
   * full-width list layout to surface the compact difficulty pill at the same
   * width as the other stat cells.
   */
  difficultySlot?: ReactNode;
}

/**
 * 4-column stats summary at the top of the right-hand stage detail panel.
 *
 * Columns: distance (editable inline) / elevation / duration / per-stage budget.
 *
 * The distance cell preserves the legacy {@link StageDistanceEditor} flow so
 * users continue to edit km inline via the pencil button. The budget cell
 * mirrors the per-stage formula already used by the trip-summary infographic
 * and the text export (food + selected/average accommodation, computed locally
 * to avoid a backend round-trip).
 */
export function StageStatsRow({
  stage,
  stageIndex,
  isFirst,
  isLast,
  isProcessing,
  readOnly = false,
  onDistanceChange,
  departureHour,
  averageSpeedKmh,
  difficultySlot,
}: StageStatsRowProps) {
  const t = useTranslations("stageStats");
  const tStage = useTranslations("stage");
  const [editingDistance, setEditingDistance] = useState(false);

  const distance = stage.distance;
  const elevation = stage.elevation;
  const elevationLoss = stage.elevationLoss ?? 0;

  const travelTime =
    departureHour !== undefined &&
    averageSpeedKmh !== undefined &&
    distance !== null &&
    distance > 0
      ? computeStageTimes(
          departureHour,
          distance,
          averageSpeedKmh,
          elevation ?? 0,
        )
      : null;

  const durationHours = travelTime
    ? travelTime.arrivalDecimal - travelTime.departureDecimal
    : null;

  // Per-stage budget — keep parity with the text-export and infographic
  // formulas so totals add up across surfaces.
  const meals = mealsForStage(isFirst, isLast);
  const foodMin = meals * MEAL_COST_MIN;
  const foodMax = meals * MEAL_COST_MAX;

  let accMin = 0;
  let accMax = 0;
  if (!isLast) {
    if (stage.selectedAccommodation) {
      accMin = stage.selectedAccommodation.estimatedPriceMin ?? 0;
      accMax = stage.selectedAccommodation.estimatedPriceMax ?? 0;
    } else if (stage.accommodations.length > 0) {
      accMin =
        stage.accommodations.reduce((a, ac) => a + ac.estimatedPriceMin, 0) /
        stage.accommodations.length;
      accMax =
        stage.accommodations.reduce((a, ac) => a + ac.estimatedPriceMax, 0) /
        stage.accommodations.length;
    }
  }

  const budgetMin = Math.round(foodMin + accMin);
  const budgetMax = Math.round(foodMax + accMax);

  return (
    <div
      className="grid grid-cols-2 md:grid-cols-4 gap-2 md:gap-3"
      data-testid="stage-stats-row"
    >
      {/* Distance — editable inline. The pencil sits next to the value (not the
          label) and its tooltip explains the ripple effect on other stages
          (recette #649). */}
      <DiffHighlight
        stageIndex={stageIndex}
        field="distance"
        changeLabel={tStage("diffDistanceChanged")}
      >
        <StatCell
          label={t("distance")}
          icon={<Bike className="h-3.5 w-3.5 text-brand" />}
          testId="stat-distance"
          value={
            editingDistance ? (
              <StageDistanceEditor
                initialDistance={distance}
                onCommit={(km) => {
                  onDistanceChange?.(km);
                  setEditingDistance(false);
                }}
                onCancel={() => setEditingDistance(false)}
              />
            ) : distance !== null ? (
              <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span>
                  {Number.isInteger(distance) ? distance : distance.toFixed(1)}
                  <span className="ml-1 text-sm font-normal text-muted-foreground">
                    km
                  </span>
                </span>
                {!readOnly && onDistanceChange && (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-7 gap-1 px-2 text-xs font-medium text-brand border-brand/40 hover:bg-brand/10 hover:text-brand cursor-pointer"
                        onClick={() => setEditingDistance(true)}
                        aria-label={tStage("editDistance")}
                        data-testid="stat-distance-edit"
                      >
                        <Pencil className="h-3.5 w-3.5" />
                        {tStage("editDistance")}
                      </Button>
                    </TooltipTrigger>
                    <TooltipContent side="top" className="max-w-[15rem]">
                      {t("distanceEditTooltip")}
                    </TooltipContent>
                  </Tooltip>
                )}
              </span>
            ) : (
              <Skeleton className="w-16 h-5" />
            )
          }
          hint={
            !readOnly &&
            onDistanceChange &&
            !editingDistance &&
            distance !== null
              ? t("distanceEditHint")
              : null
          }
        />
      </DiffHighlight>

      {/* Elevation — mountain icon in the title (recette #649). The red up
          arrow sits next to the positive gain, mirroring the blue down arrow
          next to the negative loss in the hint. */}
      <StatCell
        label={t("elevation")}
        icon={<Mountain className="h-3.5 w-3.5 text-orange-500" />}
        testId="stat-elevation"
        value={
          elevation !== null ? (
            <span className="inline-flex items-center gap-1">
              <ArrowUp
                className="h-3.5 w-3.5 text-red-500"
                aria-hidden="true"
              />
              {Math.round(elevation)}
              <span className="text-sm font-normal text-muted-foreground">
                m
              </span>
            </span>
          ) : (
            <Skeleton className="w-16 h-5" />
          )
        }
        hint={
          elevation !== null ? (
            <span className="inline-flex items-center gap-1">
              <ArrowDown className="h-3 w-3 text-blue-500" aria-hidden="true" />
              {Math.round(elevationLoss)} m
            </span>
          ) : null
        }
      />

      {/* Duration / travel time */}
      <StatCell
        label={t("duration")}
        icon={<Clock className="h-3.5 w-3.5 text-indigo-500" />}
        testId="stat-duration"
        value={
          durationHours !== null ? (
            <span title={tStage("travelTimeTooltip")}>
              {formatDuration(durationHours)}
            </span>
          ) : (
            <span className="text-sm font-normal text-muted-foreground">
              {t("durationUnavailable")}
            </span>
          )
        }
        hint={
          travelTime ? (
            <span>
              {formatDecimalHour(travelTime.departureDecimal)} →{" "}
              {formatDecimalHour(travelTime.arrivalDecimal)}
            </span>
          ) : null
        }
      />

      {/* Budget — per-stage min/max. The "+ lodging" hint was dropped; an info
          icon next to the label explains the calculation on hover (#649). */}
      <StatCell
        label={t("budget")}
        icon={<Wallet className="h-3.5 w-3.5 text-emerald-500" />}
        testId="stat-budget"
        trailing={
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                className="flex items-center text-muted-foreground hover:text-foreground transition-colors cursor-help"
                aria-label={t("budgetTooltip")}
                data-testid="stat-budget-info"
              >
                <Info className="h-3.5 w-3.5" />
              </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-[15rem]">
              {t("budgetTooltip")}
            </TooltipContent>
          </Tooltip>
        }
        value={
          isProcessing && distance === null ? (
            <Skeleton className="w-20 h-5" />
          ) : budgetMin === budgetMax ? (
            <span>{budgetMax}€</span>
          ) : (
            <span>
              {budgetMin}
              <span className="text-muted-foreground">–</span>
              {budgetMax}
              <span>€</span>
            </span>
          )
        }
      />

      {/* Optional difficulty cell — appended in the full-width list layout so it
          lines up with the other stat cells (recette #649). */}
      {difficultySlot}
    </div>
  );
}

export function formatDuration(hours: number): string {
  const total = Math.max(0, hours);
  const h = Math.floor(total);
  const m = Math.round((total - h) * 60);
  if (m === 60) return `${h + 1}h00`;
  return `${h}h${String(m).padStart(2, "0")}`;
}
