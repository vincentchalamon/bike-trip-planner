/** Difficulty thresholds for stage classification */
export const DIFFICULTY_THRESHOLDS = {
  easy: { maxDistance: 60, maxElevation: 800 },
  medium: { maxDistance: 100, maxElevation: 1500 },
} as const;

/** CSS classes for difficulty badges */
export const DIFFICULTY_COLORS = {
  easy: "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400",
  medium:
    "bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400",
  hard: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
} as const;

export type Difficulty = keyof typeof DIFFICULTY_COLORS;

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

/** Backend API base URL */
export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "https://localhost";

/**
 * Absolute site origin for building canonical/OG/sitemap URLs. Unlike API_URL,
 * this falls back on an EMPTY string too (`||`, not `??`): the mobile/export
 * build injects `NEXT_PUBLIC_API_URL=""` when the var is unset, and
 * `new URL(path, "")` throws — which would break the static sitemap/robots
 * generation. The PWA and API share the origin in iso-prod/prod.
 */
export const SITE_URL = process.env.NEXT_PUBLIC_API_URL || "https://localhost";

/**
 * GDPR/legal contact address shown on the legal & privacy pages. Each
 * self-hosted instance sets its own mailbox via `NEXT_PUBLIC_CONTACT_EMAIL`
 * (build-time inlined); the default is a generic RFC 2606 placeholder so the
 * upstream build never ships a real address.
 */
export const CONTACT_EMAIL =
  process.env.NEXT_PUBLIC_CONTACT_EMAIL || "contact@example.org";

/**
 * AI feature flag (recette #649). The whole AI surface — generation assistant
 * card, in-ride chat bubble, per-stage briefings + trip overview, and the
 * account provider/token config — is hidden unless `NEXT_PUBLIC_ENABLE_AI` is
 * explicitly `"true"`. The feature is on hold (provider quota/availability
 * issues); the code and endpoints are kept for a later re-enable, so this is a
 * single reversible switch rather than a removal.
 *
 * Default-off (fail-safe masking): prod and the iso-prod recette build mask AI
 * with no env set. It is turned on only in dev (`compose.dev.yaml`), in the CI
 * E2E build (`ci.yml` bake args) and in Vitest (`vitest.config.ts`) so the AI
 * code stays exercised. Build-time inlined like the other NEXT_PUBLIC_* reads.
 */
export function isAiFeatureEnabled(): boolean {
  return process.env.NEXT_PUBLIC_ENABLE_AI === "true";
}
