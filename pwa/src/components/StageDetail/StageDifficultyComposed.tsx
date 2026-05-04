"use client";

import { useTranslations } from "next-intl";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { Bike, Mountain, Wrench } from "lucide-react";
import {
  DIFFICULTY_THRESHOLDS,
  getDifficulty,
  type Difficulty,
} from "@/lib/constants";

const SEGMENT_COLORS: Record<Difficulty, string> = {
  easy: "bg-emerald-500",
  medium: "bg-amber-500",
  hard: "bg-red-500",
};

const TRACK_COLOR = "bg-muted";

/** Distance cap (km) above which the physical score saturates at 100. */
const PHYSICAL_SCORE_CAP_KM = Math.round(
  DIFFICULTY_THRESHOLDS.medium.maxDistance * 1.4,
); // 140

/** Elevation gain cap (m) above which the elevation score saturates at 100. */
const ELEVATION_SCORE_CAP_M = Math.round(
  DIFFICULTY_THRESHOLDS.medium.maxElevation * 1.67,
); // ~2500

/** Climbing ratio (m/km) above which the technical score saturates at 100. */
const TECHNICAL_SCORE_CAP_M_PER_KM = 60;

/** Coarse 0-100 scoring helpers — kept in lockstep with `getDifficulty`. */
export function scorePhysical(distanceKm: number): number {
  // 0 km → 0, 60 km → 33, 100 km → 67, 140+ km → 100
  if (distanceKm <= 0) return 0;
  if (distanceKm >= PHYSICAL_SCORE_CAP_KM) return 100;
  return Math.round((distanceKm / PHYSICAL_SCORE_CAP_KM) * 100);
}

export function scoreElevation(elevationM: number): number {
  // 0 m → 0, 800 m → 33, 1500 m → 67, 2500+ m → 100
  if (elevationM <= 0) return 0;
  if (elevationM >= ELEVATION_SCORE_CAP_M) return 100;
  return Math.round((elevationM / ELEVATION_SCORE_CAP_M) * 100);
}

/**
 * Technical score based on the elevation/distance ratio (gradient proxy).
 * 30 m/km is roughly hilly, 60+ m/km is mountainous.
 */
export function scoreTechnical(distanceKm: number, elevationM: number): number {
  if (distanceKm <= 0) return 0;
  const ratio = elevationM / distanceKm;
  if (ratio >= TECHNICAL_SCORE_CAP_M_PER_KM) return 100;
  return Math.round((ratio / TECHNICAL_SCORE_CAP_M_PER_KM) * 100);
}

export function scoreToDifficulty(score: number): Difficulty {
  if (score < 34) return "easy";
  if (score < 67) return "medium";
  return "hard";
}

interface BarProps {
  label: string;
  icon: React.ReactNode;
  score: number;
  difficulty: Difficulty;
  detail: string;
  testId: string;
}

function Bar({ label, icon, score, difficulty, detail, testId }: BarProps) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <div
          className="flex items-center gap-2 cursor-default select-none"
          data-testid={testId}
          data-difficulty={difficulty}
        >
          <span className="flex items-center gap-1 text-xs text-muted-foreground w-20 shrink-0">
            <span aria-hidden="true">{icon}</span>
            <span className="truncate">{label}</span>
          </span>
          <div
            className={`relative h-2 flex-1 rounded-full ${TRACK_COLOR} overflow-hidden`}
            role="progressbar"
            aria-label={label}
            aria-valuenow={score}
            aria-valuemin={0}
            aria-valuemax={100}
          >
            <div
              className={`absolute inset-y-0 left-0 ${SEGMENT_COLORS[difficulty]} transition-[width] duration-300`}
              style={{ width: `${Math.max(2, score)}%` }}
            />
          </div>
          <span className="text-xs tabular-nums text-muted-foreground w-9 text-right shrink-0">
            {score}
          </span>
        </div>
      </TooltipTrigger>
      <TooltipContent side="left" className="text-xs">
        {detail}
      </TooltipContent>
    </Tooltip>
  );
}

interface StageDifficultyComposedProps {
  distance: number;
  elevation: number;
}

/**
 * Composed difficulty gauge showing three sub-scores side by side:
 * physical (distance), technical (elevation/distance ratio) and elevation
 * (raw D+).
 *
 * Each sub-score is rendered as a horizontal bar coloured according to the
 * shared `easy/medium/hard` scale and accompanied by a tooltip describing the
 * underlying numbers. The overall difficulty (kept in sync with the legacy
 * `getDifficulty` helper) is shown as a pill in the header so users can still
 * grasp the summary at a glance.
 */
export function StageDifficultyComposed({
  distance,
  elevation,
}: StageDifficultyComposedProps) {
  const t = useTranslations("difficultyComposed");
  const tStage = useTranslations("stage");

  const physicalScore = scorePhysical(distance);
  const elevationScore = scoreElevation(elevation);
  const technicalScore = scoreTechnical(distance, elevation);

  const overall = getDifficulty(distance, elevation);
  const overallLabel =
    overall === "easy"
      ? tStage("difficultyEasy")
      : overall === "medium"
        ? tStage("difficultyMedium")
        : tStage("difficultyHard");

  // Accessible summary kept compatible with the legacy DifficultyGauge format
  // ("Label — N km, D+ M m") so screen readers and the existing E2E selectors
  // keep finding meaningful information.
  const overallAriaLabel = `${overallLabel} — ${Math.round(distance)} km, D+ ${Math.round(elevation)} m`;

  return (
    <section
      data-testid="stage-difficulty-composed"
      data-overall={overall}
      aria-label={t("ariaLabel")}
      className="rounded-lg border border-border bg-card/40 p-3"
    >
      <header className="mb-2 flex items-center justify-between gap-2">
        <h3 className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
          {t("title")}
        </h3>
        <span
          className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ${
            overall === "easy"
              ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400"
              : overall === "medium"
                ? "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400"
                : "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400"
          }`}
          data-testid="stage-difficulty-overall"
          aria-label={overallAriaLabel}
        >
          <span
            aria-hidden="true"
            className={`h-1.5 w-1.5 rounded-full ${SEGMENT_COLORS[overall]}`}
          />
          {overallLabel}
        </span>
      </header>

      <div className="space-y-1.5">
        <Bar
          label={t("physical")}
          icon={<Bike className="h-3 w-3" />}
          score={physicalScore}
          difficulty={scoreToDifficulty(physicalScore)}
          detail={t("physicalDetail", { distance: Math.round(distance) })}
          testId="stage-difficulty-physical"
        />
        <Bar
          label={t("technical")}
          icon={<Wrench className="h-3 w-3" />}
          score={technicalScore}
          difficulty={scoreToDifficulty(technicalScore)}
          detail={t("technicalDetail", {
            ratio:
              distance > 0 ? Math.round((elevation / distance) * 10) / 10 : 0,
          })}
          testId="stage-difficulty-technical"
        />
        <Bar
          label={t("elevation")}
          icon={<Mountain className="h-3 w-3" />}
          score={elevationScore}
          difficulty={scoreToDifficulty(elevationScore)}
          detail={t("elevationDetail", { elevation: Math.round(elevation) })}
          testId="stage-difficulty-elevation"
        />
      </div>
    </section>
  );
}
