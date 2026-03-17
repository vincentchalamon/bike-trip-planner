/** Estimated cost per meal (€). */
export const MEAL_COST_MIN = 12;
export const MEAL_COST_MAX = 20;

/**
 * Number of meals to count for a stage given its position in the trip.
 *
 * - First stage: lunch + dinner (breakfast already eaten at home) → 2 meals
 * - Last stage:  breakfast + lunch (dinner back home)             → 2 meals
 * - Both first and last (single-stage trip): lunch only           → 1 meal
 * - All other stages: breakfast + lunch + dinner                  → 3 meals
 */
export function mealsForStage(isFirst: boolean, isLast: boolean): number {
  return Math.max(1, 3 - (isFirst ? 1 : 0) - (isLast ? 1 : 0));
}
