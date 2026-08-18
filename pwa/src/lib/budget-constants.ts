// Budget primitives now live framework-free in @btp/core (ADR-055), shared with
// mobile. Re-exported here so existing `@/lib/budget-constants` imports keep
// working unchanged.
export { MEAL_COST_MIN, MEAL_COST_MAX, mealsForStage } from "@btp/core";
