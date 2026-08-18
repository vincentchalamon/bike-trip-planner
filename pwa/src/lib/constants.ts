// Stage difficulty thresholds + classification now live framework-free in
// @btp/core (ADR-055), shared with mobile. Re-exported here so existing
// `@/lib/constants` imports keep working unchanged. Only the Tailwind badge
// colours and the env-derived URLs stay web-specific.
export {
  DIFFICULTY_THRESHOLDS,
  getDifficulty,
  type Difficulty,
} from "@btp/core";

/** CSS classes for difficulty badges */
export const DIFFICULTY_COLORS = {
  easy: "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400",
  medium:
    "bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400",
  hard: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
} as const;

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
