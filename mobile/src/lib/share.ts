// All pure share logic (budget constants + trip totals/estimate, difficulty,
// formatted text) lives framework-free in @btp/core and is shared with the web
// (ADR-055). Re-exported here so the mobile share UI keeps a single import path.
export {
  MEAL_COST_MIN,
  MEAL_COST_MAX,
  mealsForStage,
  computeTripTotals,
  computeEstimatedBudget,
  getDifficulty,
  computeOverallDifficulty,
  buildTripText,
} from '@btp/core';
export type {
  TripTotals,
  BudgetEstimate,
  Difficulty,
  DifficultyLabels,
  OverallDifficulty,
  TextExportParams,
} from '@btp/core';
