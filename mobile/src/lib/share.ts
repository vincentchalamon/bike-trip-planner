import type { StageData } from '@btp/core';

// Pure share helpers (#1048): trip totals, budget estimate, overall difficulty
// and the formatted-text builder. Mirror the web semantics (pwa's
// budget-constants.ts, trip-planner.tsx estimatedBudget, infographic.ts and
// text-export.ts) so web and mobile share produce the same numbers and text.

/** Estimated cost per meal (EUR). Mirror of pwa's budget-constants. */
export const MEAL_COST_MIN = 12;
export const MEAL_COST_MAX = 20;

/**
 * Meals counted for a stage given its position:
 * - first stage: lunch + dinner (2)
 * - last stage: breakfast + lunch (2)
 * - single-stage trip (first and last): lunch only (1)
 * - other stages: breakfast + lunch + dinner (3)
 */
export function mealsForStage(isFirst: boolean, isLast: boolean): number {
  return Math.max(1, 3 - (isFirst ? 1 : 0) - (isLast ? 1 : 0));
}

export interface TripTotals {
  totalDistance: number;
  totalElevation: number;
  totalElevationLoss: number;
}

/** Sum distance / ascent / descent over every stage (rest days contribute 0). */
export function computeTripTotals(stages: StageData[]): TripTotals {
  return stages.reduce<TripTotals>(
    (acc, s) => ({
      totalDistance: acc.totalDistance + s.distance,
      totalElevation: acc.totalElevation + s.elevation,
      totalElevationLoss: acc.totalElevationLoss + (s.elevationLoss ?? 0),
    }),
    { totalDistance: 0, totalElevation: 0, totalElevationLoss: 0 },
  );
}

export interface BudgetEstimate {
  min: number;
  max: number;
}

/**
 * Estimated budget (accommodation + meals) for the whole trip. Mirrors the web
 * `estimatedBudget` memo: the last active stage has no accommodation (rider is
 * home), rest days count 3 meals, and a stage with no selected accommodation
 * uses the average price of the found ones.
 */
export function computeEstimatedBudget(stages: StageData[]): BudgetEstimate {
  const nonRestStages = stages.filter((s) => !s.isRestDay);
  const lastActiveIndex = nonRestStages.length - 1;
  const restDayCount = stages.filter((s) => s.isRestDay).length;
  let accMin = 0;
  let accMax = 0;
  let foodMin = restDayCount * 3 * MEAL_COST_MIN;
  let foodMax = restDayCount * 3 * MEAL_COST_MAX;
  nonRestStages.forEach((s, i) => {
    const isFirst = i === 0;
    const isLast = i === lastActiveIndex;
    foodMin += mealsForStage(isFirst, isLast) * MEAL_COST_MIN;
    foodMax += mealsForStage(isFirst, isLast) * MEAL_COST_MAX;
    if (!isLast) {
      if (s.selectedAccommodation) {
        accMin += s.selectedAccommodation.estimatedPriceMin ?? 0;
        accMax += s.selectedAccommodation.estimatedPriceMax ?? 0;
      } else if (s.accommodations.length > 0) {
        accMin +=
          s.accommodations.reduce((a, ac) => a + ac.estimatedPriceMin, 0) /
          s.accommodations.length;
        accMax +=
          s.accommodations.reduce((a, ac) => a + ac.estimatedPriceMax, 0) /
          s.accommodations.length;
      }
    }
  });
  return { min: accMin + foodMin, max: accMax + foodMax };
}

// ---------------------------------------------------------------------------
// Difficulty (mirror of pwa/src/lib/constants.ts)
// ---------------------------------------------------------------------------

export type Difficulty = 'easy' | 'medium' | 'hard';

const DIFFICULTY_THRESHOLDS = {
  easy: { maxDistance: 60, maxElevation: 800 },
  medium: { maxDistance: 100, maxElevation: 1500 },
} as const;

export function getDifficulty(distance: number, elevation: number): Difficulty {
  if (
    distance < DIFFICULTY_THRESHOLDS.easy.maxDistance &&
    elevation < DIFFICULTY_THRESHOLDS.easy.maxElevation
  ) {
    return 'easy';
  }
  if (
    distance < DIFFICULTY_THRESHOLDS.medium.maxDistance &&
    elevation < DIFFICULTY_THRESHOLDS.medium.maxElevation
  ) {
    return 'medium';
  }
  return 'hard';
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

/** Overall trip difficulty (mirror of infographic.ts computeOverallDifficulty). */
export function computeOverallDifficulty(
  stages: StageData[],
  labels: DifficultyLabels,
): OverallDifficulty {
  const activeStages = stages.filter((s) => !s.isRestDay);
  if (activeStages.length === 0) {
    return { key: 'easy', label: labels.easy, color: '#22c55e' };
  }
  const difficulties = activeStages.map((s) =>
    getDifficulty(s.distance, s.elevation),
  );
  const hardCount = difficulties.filter((d) => d === 'hard').length;
  const mediumCount = difficulties.filter((d) => d === 'medium').length;
  if (hardCount > activeStages.length * 0.3) {
    return { key: 'hard', label: labels.hard, color: '#ef4444' };
  }
  if (mediumCount + hardCount > activeStages.length * 0.5) {
    return { key: 'medium', label: labels.medium, color: '#f97316' };
  }
  return { key: 'easy', label: labels.easy, color: '#22c55e' };
}

// ---------------------------------------------------------------------------
// Formatted text (mirror of pwa/src/lib/text-export.ts)
// ---------------------------------------------------------------------------

function formatDate(startDate: string | null, dayNumber: number): string {
  const [year = 0, month = 0, day = 0] = (
    startDate ??
    (() => {
      const n = new Date();
      return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-${String(n.getDate()).padStart(2, '0')}`;
    })()
  )
    .split('-')
    .map(Number);
  const base = new Date(year, month - 1, day);
  const date = new Date(
    base.getFullYear(),
    base.getMonth(),
    base.getDate() + dayNumber - 1,
  );
  return date.toLocaleDateString(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
}

function formatStageLine(
  stage: StageData,
  startDate: string | null,
  stageIndex: number,
  totalActiveStages: number,
): string {
  const isFirst = stageIndex === 0;
  const isLast = stageIndex === totalActiveStages - 1;
  const date = formatDate(startDate, stage.dayNumber);
  const distance = `${Math.round(stage.distance)}km`;
  const elevUp = `⬆️ ${Math.round(stage.elevation)}m`;
  const elevDown = `⬇️ ${Math.round(stage.elevationLoss ?? 0)}m`;

  let line = `${date} : ${distance}, ${elevUp} ${elevDown}`;

  const acc = isLast
    ? null
    : (stage.selectedAccommodation ?? stage.accommodations[0] ?? null);

  const meals = mealsForStage(isFirst, isLast);
  const foodMin = meals * MEAL_COST_MIN;
  const foodMax = meals * MEAL_COST_MAX;

  if (acc) {
    const accMin = Number(acc.estimatedPriceMin);
    const accMax = Number(acc.estimatedPriceMax);
    const hasAccPrice =
      !isNaN(accMin) && !isNaN(accMax) && (accMin > 0 || accMax > 0);

    let accPart = acc.name;
    if (acc.url) {
      accPart = `${acc.name} (${acc.url})`;
    }

    if (hasAccPrice) {
      const totalMin = Math.round(accMin + foodMin);
      const totalMax = Math.round(accMax + foodMax);
      const budgetStr =
        acc.isExactPrice || totalMin === totalMax
          ? `${totalMax}€`
          : `${totalMin}-${totalMax}€`;
      accPart = `${accPart} ${budgetStr}`;
    }

    line = `${line}, ${accPart}`;
  } else {
    line = `${line}, ${foodMin}-${foodMax}€`;
  }

  return line;
}

export interface TripTextParams {
  title: string;
  totalDistance: number | null;
  totalElevation: number | null;
  totalElevationLoss: number | null;
  sourceUrl: string;
  stages: StageData[];
  startDate: string | null;
  /** Public share link, appended under a "view online" line when present. */
  shareUrl?: string | null;
  labels: {
    totalDistance: string;
    totalElevation: string;
    viewOnline: string;
  };
}

/** Build the shareable trip text (title, totals, per-stage budget, links). */
export function buildTripText(params: TripTextParams): string {
  const {
    title,
    totalDistance,
    totalElevation,
    totalElevationLoss,
    sourceUrl,
    stages,
    startDate,
    shareUrl,
    labels,
  } = params;

  const lines: string[] = [];

  lines.push(title);
  lines.push('');

  if (totalDistance !== null) {
    lines.push(`- 🚴 ${labels.totalDistance} : ${Math.round(totalDistance)}km`);
  }
  if (totalElevation !== null) {
    lines.push(
      `- 🏔 ${labels.totalElevation} : ⬆️ ${Math.round(totalElevation)}m ⬇️ ${Math.round(totalElevationLoss ?? 0)}m`,
    );
  }
  if (sourceUrl) {
    lines.push(`- 🧭 ${sourceUrl}`);
  }

  const activeStages = stages.filter((s) => !s.isRestDay);
  if (activeStages.length > 0) {
    lines.push('');
    activeStages.forEach((stage, i) => {
      lines.push(formatStageLine(stage, startDate, i, activeStages.length));
    });
  }

  const text = lines.join('\n');
  if (shareUrl) {
    return `${text}\n\n${labels.viewOnline} : ${shareUrl}`;
  }
  return text;
}
