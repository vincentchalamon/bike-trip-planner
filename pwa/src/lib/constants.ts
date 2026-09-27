// Stage difficulty thresholds + classification now live framework-free in
// @btp/core (ADR-055), shared with mobile. Re-exported here so existing
// `@/lib/constants` imports keep working unchanged. Only the env-derived URLs
// stay web-specific.
export {
  DIFFICULTY_THRESHOLDS,
  getDifficulty,
  type Difficulty,
} from "@btp/core";

/** Backend API base URL */
export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "https://localhost";

/**
 * Absolute site origin for building canonical/OG URLs. Unlike API_URL,
 * this falls back on an EMPTY string too (`||`, not `??`): the mobile/export
 * build injects `NEXT_PUBLIC_API_URL=""` when the var is unset, and
 * `new URL(path, "")` throws — which would break metadata generation.
 * The PWA and API share the origin in iso-prod/prod.
 */
export const SITE_URL = process.env.NEXT_PUBLIC_API_URL || "https://localhost";

/**
 * GDPR/legal contact address shown on the legal & privacy pages. Each
 * self-hosted instance sets its own mailbox via the root-level `CONTACT_EMAIL`,
 * which compose maps onto `NEXT_PUBLIC_CONTACT_EMAIL` (build-time inlined).
 */
export const CONTACT_EMAIL =
  process.env.NEXT_PUBLIC_CONTACT_EMAIL || "contact@bike-trip-planner.com";
