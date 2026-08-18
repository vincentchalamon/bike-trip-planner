import type { StageData } from "./schemas";

// Budget primitives shared by web and mobile (ADR-055). Framework-free — no
// React/RN/Next imports. Single source of truth for the per-meal cost, the meal
// count per stage, and the trip-level budget/totals both platforms display.

/** Estimated cost per meal (EUR). */
export const MEAL_COST_MIN = 12;
export const MEAL_COST_MAX = 20;

/**
 * Number of meals to count for a stage given its position in the trip.
 *
 * - First stage: lunch + dinner (breakfast already eaten at home) -> 2 meals
 * - Last stage:  breakfast + lunch (dinner back home)             -> 2 meals
 * - Both first and last (single-stage trip): lunch only           -> 1 meal
 * - All other stages: breakfast + lunch + dinner                  -> 3 meals
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
 * Estimated budget (accommodation + meals) for the whole trip. The last active
 * stage carries no accommodation (rider is home), rest days count 3 meals, and a
 * stage with no selected accommodation uses the average price of the found ones.
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
