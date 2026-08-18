// Rider pacing presets now live framework-free in @btp/core so pwa and mobile
// share one source of truth (ADR-055, #1046). Re-exported here to keep the
// existing `@/lib/pacing-presets` import path stable for pwa call sites.
export {
  type RiderPreset,
  PRESETS,
  fromFatiguePercent,
  fromElevationPercent,
  toFatiguePercent,
  toElevationPercent,
  getActivePresetKey,
} from "@btp/core/pacing-presets";
