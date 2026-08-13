import type { EventData } from '@btp/core';

// Number of events shown before the "see more" toggle (mirrors the web).
export const DEFAULT_VISIBLE_EVENTS = 3;

// Chronological order by start date (mirrors the web events-panel).
export function sortEvents(events: EventData[]): EventData[] {
  return [...events].sort(
    (a, b) => new Date(a.startDate).getTime() - new Date(b.startDate).getTime(),
  );
}

// Backend event type URIs → i18n key suffix under `trip.blocks.eventType.*`.
const EVENT_TYPE_KEYS: Record<string, string> = {
  'schema:Festival': 'festival',
  'schema:Exhibition': 'exhibition',
  'schema:MusicEvent': 'musicEvent',
  'urn:resource:FairOrShow': 'fairOrShow',
};

// The i18n key for an event type, or null when the raw type should be shown.
export function eventTypeKey(type: string): string | null {
  return EVENT_TYPE_KEYS[type] ?? null;
}

// Compact day/month range; a single day when start and end fall on the same day.
export function formatEventDateRange(
  startDate: string,
  endDate: string,
  locale: string,
): string {
  const fmt = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' });
  const start = fmt.format(new Date(startDate));
  const end = fmt.format(new Date(endDate));
  return start === end ? start : `${start} – ${end}`;
}
