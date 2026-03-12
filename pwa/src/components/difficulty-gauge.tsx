"use client";

import { useTranslations } from "next-intl";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { DIFFICULTY_COLORS, DIFFICULTY_THRESHOLDS } from "@/lib/constants";
import type { Difficulty } from "@/lib/constants";
import type { AlertData } from "@/lib/validation/schemas";

/** Maximum values used to normalise each score to a 0–100 range. */
const MAX_DISTANCE_KM = DIFFICULTY_THRESHOLDS.medium.maxDistance; // 100 km → 100 %
const MAX_ELEVATION_M = DIFFICULTY_THRESHOLDS.medium.maxElevation; // 1500 m → 100 %

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}

/**
 * Returns a 0–100 score for each difficulty factor.
 *
 * Surface difficulty is inferred from terrain-source warning/critical alerts:
 * the "terrain" source groups surface, steep-gradient and traffic alerts.
 * Any warning/critical alert in that group signals a challenging surface.
 */
function computeScores(
  distance: number,
  elevation: number,
  alerts: AlertData[],
): { distanceScore: number; elevationScore: number; surfaceScore: number } {
  const distanceScore = clamp(
    Math.round((distance / MAX_DISTANCE_KM) * 100),
    0,
    100,
  );
  const elevationScore = clamp(
    Math.round((elevation / MAX_ELEVATION_M) * 100),
    0,
    100,
  );
  const hasSurfaceDifficulty = alerts.some(
    (a) =>
      a.source === "terrain" && (a.type === "warning" || a.type === "critical"),
  );
  const surfaceScore = hasSurfaceDifficulty ? 100 : 0;

  return { distanceScore, elevationScore, surfaceScore };
}

interface SegmentBarProps {
  score: number;
  label: string;
  colorClass: string;
}

function SegmentBar({ score, label, colorClass }: SegmentBarProps) {
  return (
    <div className="relative h-1.5 w-14 rounded-full bg-muted overflow-hidden">
      <div
        className={`absolute inset-y-0 left-0 rounded-full transition-all duration-300 ${colorClass}`}
        style={{ width: `${score}%` }}
        role="progressbar"
        aria-label={label}
        aria-valuenow={score}
        aria-valuemin={0}
        aria-valuemax={100}
      />
    </div>
  );
}

interface DifficultyGaugeProps {
  difficulty: Difficulty;
  distance: number;
  elevation: number;
  alerts: AlertData[];
}

const difficultyDotClasses: Record<Difficulty, string> = {
  easy: "bg-green-500",
  medium: "bg-orange-500",
  hard: "bg-red-500",
};

const difficultyLabelKeys = {
  easy: "difficultyEasy",
  medium: "difficultyMedium",
  hard: "difficultyHard",
} as const;

export function DifficultyGauge({
  difficulty,
  distance,
  elevation,
  alerts,
}: DifficultyGaugeProps) {
  const t = useTranslations("stage");
  const { distanceScore, elevationScore, surfaceScore } = computeScores(
    distance,
    elevation,
    alerts,
  );
  const hasSurfaceDifficulty = surfaceScore > 0;

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <div
          className={`inline-flex items-center gap-1.5 px-2 py-1 rounded-full cursor-default select-none ${DIFFICULTY_COLORS[difficulty]}`}
          aria-label={`${t(difficultyLabelKeys[difficulty])} — ${t("gaugeDistanceLabel", { value: Math.round(distance) })}, ${t("gaugeElevationLabel", { value: Math.round(elevation) })}`}
        >
          {/* Difficulty dot */}
          <span
            className={`h-1.5 w-1.5 rounded-full shrink-0 ${difficultyDotClasses[difficulty]}`}
          />

          {/* Label */}
          <span className="text-xs font-medium">
            {t(difficultyLabelKeys[difficulty])}
          </span>

          {/* Mini stacked bar gauges */}
          <div className="flex flex-col gap-0.5 shrink-0">
            <SegmentBar
              score={distanceScore}
              label={t("gaugeDistanceLabel", {
                value: Math.round(distance),
              })}
              colorClass="bg-sky-500"
            />
            <SegmentBar
              score={elevationScore}
              label={t("gaugeElevationLabel", {
                value: Math.round(elevation),
              })}
              colorClass="bg-orange-500"
            />
            {hasSurfaceDifficulty && (
              <SegmentBar
                score={surfaceScore}
                label={t("gaugeSurfaceLabel")}
                colorClass="bg-amber-700"
              />
            )}
          </div>
        </div>
      </TooltipTrigger>

      <TooltipContent side="bottom" className="text-xs space-y-1 max-w-48">
        <p className="font-semibold mb-1">
          {t(difficultyLabelKeys[difficulty])}
        </p>
        <p>
          {t("gaugeDistanceLabel", { value: Math.round(distance) })}
          {" — "}
          {distanceScore}%
        </p>
        <p>
          {t("gaugeElevationLabel", { value: Math.round(elevation) })}
          {" — "}
          {elevationScore}%
        </p>
        {hasSurfaceDifficulty && <p>{t("gaugeSurfaceLabel")}</p>}
      </TooltipContent>
    </Tooltip>
  );
}
