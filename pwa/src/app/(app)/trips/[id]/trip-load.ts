// Pure load/retry policy for the trip detail page, split out so it can be unit
// tested without mounting the (map-heavy) TripPlanner tree.

// Right after creation the trip row may not be readable yet (commit / ownership
// association timing), so the first /detail can transiently 404. Retry a few
// times before rendering "Voyage introuvable" (recette #649, bug C).
export const INITIAL_RETRY_MS = 1200;
export const MAX_INITIAL_ATTEMPTS = 5;

// While the structural computation hasn't produced stages, keep re-fetching
// /detail: the SSE subscription is re-established only after the post-creation
// navigation, so `route_parsed` / `stages_computed` emitted in between are never
// received and the loader stays up until a manual reload (recette #649, bug B).
// Bounded so a genuinely stuck computation does not poll forever.
export const RESYNC_INTERVAL_MS = 2500;
export const MAX_RESYNC_MS = 90_000;

/**
 * The trip still needs re-syncing while its structural computation hasn't
 * produced stages (status not `ready` AND no stages in the payload). Once stages
 * are present the view is renderable, so polling stops — this also avoids
 * needless polling for payloads that omit `status`.
 */
export function needsResync(data: {
  status?: string | null;
  stages?: readonly unknown[] | null;
}): boolean {
  return (
    (data.status ?? "draft") !== "ready" && (data.stages ?? []).length === 0
  );
}

/**
 * Whether a failed initial /detail load should be retried.
 *
 * A network blip (`res === null`), or a 404 on the trip we just created
 * (`ownsFreshTrip` — already in the store via setTrip before the post-creation
 * router.push, so the row may not be readable yet), is transient. A 404 on a
 * foreign / missing trip (object-level authz is hidden as 404, ADR-038) and
 * definitive errors (5xx, 403) are NOT retried — they surface immediately.
 * Retries are capped at {@link MAX_INITIAL_ATTEMPTS} so the loader can't spin
 * forever.
 */
export function shouldRetryInitialLoad(
  res: Response | null,
  attempt: number,
  ownsFreshTrip: boolean,
): boolean {
  if (attempt >= MAX_INITIAL_ATTEMPTS) return false;

  return res === null || (res.status === 404 && ownsFreshTrip);
}
