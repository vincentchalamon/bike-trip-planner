/**
 * Plausible custom-event helper.
 *
 * Emits goal-conversion events to the self-hosted Plausible instance loaded by
 * {@link PlausibleScript}. The script only injects `window.plausible` once both
 * the env config and analytics consent are present, so {@link trackEvent} is a
 * no-op whenever the script is absent (consent refused or not configured).
 */

/** Custom events emitted across the app. Union-typed to catch typos. */
export type PlausibleEvent =
  // Import sources & platforms
  | "import_komoot"
  | "import_strava"
  | "import_rwgps"
  | "import_gpx"
  // Feature value & retention/UX
  | "trip_created"
  | "trip_shared"
  | "accommodation_selected"
  | "alert_action_clicked"
  | "ai_chat_opened";

/**
 * Send a custom event to Plausible. No-op when `window.plausible` is undefined
 * (script not loaded: consent refused or analytics not configured).
 */
export function trackEvent(
  name: PlausibleEvent,
  props?: Record<string, string | number | boolean>,
): void {
  if (typeof window === "undefined") return;
  window.plausible?.(name, props ? { props } : undefined);
}
