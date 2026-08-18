import type { StageData } from '@btp/core';
import { MEAL_COST_MIN, MEAL_COST_MAX, mealsForStage } from '@btp/core';

// The pure share logic (budget constants, meal count, difficulty, formatted
// text) lives framework-free in @btp/core and is shared with the web (ADR-055).
// Re-export the pieces the mobile share UI consumes so call sites keep one
// import path, and add the mobile-only trip aggregates below.
export {
  MEAL_COST_MIN,
  MEAL_COST_MAX,
  mealsForStage,
  getDifficulty,
  computeOverallDifficulty,
  buildTripText,
} from '@btp/core';
export type {
  Difficulty,
  DifficultyLabels,
  OverallDifficulty,
  TextExportParams,
} from '@btp/core';

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
