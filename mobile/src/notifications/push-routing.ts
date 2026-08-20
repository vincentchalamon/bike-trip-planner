import type { Href } from 'expo-router';

// Shape of the `data` payload the backend attaches to a push. Every field is
// optional; routing degrades to null when nothing actionable is present. The
// backend keys on the same three server-push categories as the prefs store
// (weatherSafety, analysisDone, zoneOpening).
export type PushData = {
  category?: string;
  tripId?: string;
  stageIndex?: string | number;
};

// Map a notification payload to the in-app route to open on tap. Pure, so it is
// unit-testable without a navigator. Most specific target wins: a stage-scoped
// payload opens the stage, a trip-scoped one the roadbook, a zone-opening
// announcement the trip-creation tab (where a newly-opened region is usable).
export function resolvePushRoute(data: PushData | null | undefined): Href | null {
  if (!data) return null;
  const stage = data.stageIndex;
  if (data.tripId && stage != null && `${stage}` !== '') {
    return `/trip/${data.tripId}/stage/${stage}` as Href;
  }
  if (data.tripId) {
    return `/trip/${data.tripId}` as Href;
  }
  if (data.category === 'zoneOpening') {
    return '/(tabs)/create' as Href;
  }
  return null;
}
