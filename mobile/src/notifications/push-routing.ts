import type { Href } from 'expo-router';

// Shape of the `data` payload the backend attaches to a push. Every field is
// optional; routing degrades to null when nothing actionable is present. The
// backend keys on the same three server-push categories as the prefs store
// (weatherSafety, analysisDone, zoneOpening).
export type PushData = {
  category?: string;
  tripId?: string;
  stageId?: string;
};

// Map a notification payload to the in-app route to open on tap. Pure, so it is
// unit-testable without a navigator. Most specific target wins: a stage-scoped
// payload opens the stage, a trip-scoped one the roadbook, a zone-opening
// announcement the trip-creation tab (where a newly-opened region is usable).
// Same pattern as SAFE_ID in src/store/trip-cache.ts, kept here so this module stays
// pure. The payload comes from outside the app: an id carrying `/`, `?` or `..` would
// otherwise be interpolated into the route as path or query.
const SAFE_ID = /^[A-Za-z0-9_-]+$/;

function safeId(value: unknown): string | null {
  return typeof value === 'string' && SAFE_ID.test(value) ? value : null;
}

export function resolvePushRoute(data: PushData | null | undefined): Href | null {
  if (!data) return null;
  const tripId = safeId(data.tripId);
  const stageId = safeId(data.stageId);
  if (tripId && stageId) {
    return `/trip/${tripId}/stage/${stageId}` as Href;
  }
  if (tripId) {
    return `/trip/${tripId}` as Href;
  }
  if (data.category === 'zoneOpening') {
    return '/(tabs)/create' as Href;
  }
  return null;
}
