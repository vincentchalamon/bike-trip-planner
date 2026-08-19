// Pure decision layer for the two *local* notification categories (ADR-055
// channels: `offlineNotReady` and `tripNoDate` are `local`, scheduled on-device).
// Given the current trips + the user's per-category toggles, it yields the exact
// set of schedule/cancel actions; the reconcile layer (reconcile.ts) applies them
// through expo-notifications. No native import here so the logic stays unit-testable.

/** The categories delivered on-device (the `local` channel in notification-prefs). */
export const LOCAL_CATEGORIES = ['offlineNotReady', 'tripNoDate'] as const;
export type LocalCategory = (typeof LOCAL_CATEGORIES)[number];

// `offlineNotReady` fires this long before departure; `tripNoDate` this long after
// creation. Both are 2 days — the window named in the category descriptions.
export const OFFLINE_LEAD_MS = 2 * 24 * 60 * 60 * 1000;
export const NO_DATE_DELAY_MS = 2 * 24 * 60 * 60 * 1000;

/** The trip facts the local triggers depend on (a subset of TripListItem). */
export interface TripNotificationInput {
  id: string;
  /** ISO departure date, or null when the trip has no dates yet. */
  startDate: string | null;
  /** ISO creation timestamp (only exposed on the list item, not on /detail). */
  createdAt: string;
  /** Whether the trip's data is cached for offline use. */
  offlineReady: boolean;
}

export interface CategoryMessage {
  title: string;
  body: string;
}
export type LocalMessages = Record<LocalCategory, CategoryMessage>;

export interface ScheduleAction {
  type: 'schedule';
  identifier: string;
  category: LocalCategory;
  tripId: string;
  /** Epoch ms at which the OS should present the notification. */
  fireAt: number;
  title: string;
  body: string;
}
export interface CancelAction {
  type: 'cancel';
  identifier: string;
  category: LocalCategory;
  tripId: string;
}
export type NotificationAction = ScheduleAction | CancelAction;

/** Stable, per-(category, trip) identifier so scheduling is idempotent. */
export function notificationIdentifier(category: LocalCategory, tripId: string): string {
  return `btp:${category}:${tripId}`;
}

// Warn that a trip is not cached for offline use, timed to the approach of its
// departure. Scheduled only while the departure is still in the future and the
// data is not yet cached; cancelled once the cache is ready or the date passes.
function offlineNotReadyAction(
  trip: TripNotificationInput,
  prefs: Record<LocalCategory, boolean>,
  messages: LocalMessages,
  now: number,
): NotificationAction {
  const identifier = notificationIdentifier('offlineNotReady', trip.id);
  const departure = trip.startDate ? Date.parse(trip.startDate) : NaN;
  const shouldSchedule =
    prefs.offlineNotReady &&
    !trip.offlineReady &&
    Number.isFinite(departure) &&
    departure > now;
  if (!shouldSchedule) {
    return { type: 'cancel', identifier, category: 'offlineNotReady', tripId: trip.id };
  }
  return {
    type: 'schedule',
    identifier,
    category: 'offlineNotReady',
    tripId: trip.id,
    fireAt: Math.max(now, departure - OFFLINE_LEAD_MS),
    ...messages.offlineNotReady,
  };
}

// Remind the rider to set dates on a trip created without any. Scheduled while the
// trip has no startDate; cancelled the moment a date is defined.
function tripNoDateAction(
  trip: TripNotificationInput,
  prefs: Record<LocalCategory, boolean>,
  messages: LocalMessages,
  now: number,
): NotificationAction {
  const identifier = notificationIdentifier('tripNoDate', trip.id);
  const shouldSchedule = prefs.tripNoDate && !trip.startDate;
  if (!shouldSchedule) {
    return { type: 'cancel', identifier, category: 'tripNoDate', tripId: trip.id };
  }
  const created = Date.parse(trip.createdAt);
  const base = Number.isFinite(created) ? created : now;
  return {
    type: 'schedule',
    identifier,
    category: 'tripNoDate',
    tripId: trip.id,
    fireAt: Math.max(now, base + NO_DATE_DELAY_MS),
    ...messages.tripNoDate,
  };
}

/**
 * One schedule-or-cancel action per (category, trip). Actions are scoped to the
 * trips passed in, so the reconcile layer never touches notifications for trips it
 * has not been told about (the list is paginated).
 */
export function planLocalNotifications(input: {
  trips: TripNotificationInput[];
  prefs: Record<LocalCategory, boolean>;
  messages: LocalMessages;
  now?: number;
}): NotificationAction[] {
  const now = input.now ?? Date.now();
  return input.trips.flatMap((trip) => [
    offlineNotReadyAction(trip, input.prefs, input.messages, now),
    tripNoDateAction(trip, input.prefs, input.messages, now),
  ]);
}
