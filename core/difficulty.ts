import type { StageData } from "./schemas";

// Stage difficulty classification shared by web and mobile (ADR-055).
// Framework-free — no React/RN/Next imports. The CSS/Tailwind badge colours stay
// in the web layer; here we keep only the pure thresholds + classification.

/** Difficulty thresholds for stage classification. */
export const DIFFICULTY_THRESHOLDS = {
  easy: { maxDistance: 60, maxElevation: 800 },
  medium: { maxDistance: 100, maxElevation: 1500 },
} as const;

export type Difficulty = "easy" | "medium" | "hard";

export function getDifficulty(
  distance: number | null,
  elevation: number | null,
): Difficulty {
  const d = distance ?? 0;
  const e = elevation ?? 0;
  if (
    d < DIFFICULTY_THRESHOLDS.easy.maxDistance &&
    e < DIFFICULTY_THRESHOLDS.easy.maxElevation
  )
    return "easy";
  if (
    d < DIFFICULTY_THRESHOLDS.medium.maxDistance &&
    e < DIFFICULTY_THRESHOLDS.medium.maxElevation
  )
    return "medium";
  return "hard";
}

export interface DifficultyLabels {
  easy: string;
  medium: string;
  hard: string;
}

export interface OverallDifficulty {
  key: Difficulty;
  label: string;
  color: string;
}

/**
 * Overall trip difficulty from its active stages. A trip is "hard" when more
 * than 30% of stages are hard, "medium" when hard+medium exceed 50%, else
 * "easy". Returns the caller-provided label and a fixed accent colour.
 */
export function computeOverallDifficulty(
  stages: StageData[],
  labels: DifficultyLabels,
): OverallDifficulty {
  const activeStages = stages.filter((s) => !s.isRestDay);
  if (activeStages.length === 0) {
    return { key: "easy", label: labels.easy, color: "#22c55e" };
  }
  const difficulties = activeStages.map((s) =>
    getDifficulty(s.distance, s.elevation),
  );
  const hardCount = difficulties.filter((d) => d === "hard").length;
  const mediumCount = difficulties.filter((d) => d === "medium").length;
  if (hardCount > activeStages.length * 0.3) {
    return { key: "hard", label: labels.hard, color: "#ef4444" };
  }
  if (mediumCount + hardCount > activeStages.length * 0.5) {
    return { key: "medium", label: labels.medium, color: "#f97316" };
  }
  return { key: "easy", label: labels.easy, color: "#22c55e" };
}
