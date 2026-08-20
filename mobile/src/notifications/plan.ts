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
  // True only when the reminder is STILL active but suppressed because a past-due
  // one-shot already fired and is recorded in `delivered`. Distinguishes this from
  // a genuine resolution (condition no longer active): reconcile must keep the
  // delivered mark here, else the next pass would re-schedule and re-fire it.
  suppressedDelivered: boolean;
}
export type NotificationAction = ScheduleAction | CancelAction;

// Namespace prefix for every identifier this feature schedules, so its ids never
// collide with another feature's.
const ID_PREFIX = 'btp:';

/** Stable, per-(category, trip) identifier so scheduling is idempotent. */
export function notificationIdentifier(category: LocalCategory, tripId: string): string {
  return `${ID_PREFIX}${category}:${tripId}`;
}

// A one-shot whose ideal fire time is already in the past (rawFireAt <= now) is
// clamped to `now` and fires immediately. Once fired it drops out of the scheduled
// set, so without the persistent `delivered` guard reconcile keeps re-scheduling it
// every pass (the id looks unscheduled). Suppress the re-schedule once delivered; a
// future rawFireAt (e.g. departure pushed back) re-arms it, and reconcile clears the
// delivered mark when it re-schedules that future instance.
function buildAction(
  category: LocalCategory,
  tripId: string,
  active: boolean,
  rawFireAt: number,
  now: number,
  message: CategoryMessage,
  delivered: ReadonlySet<string>,
): NotificationAction {
  const identifier = notificationIdentifier(category, tripId);
  const pastDue = rawFireAt <= now;
  const suppressedDelivered = active && pastDue && delivered.has(identifier);
  const shouldSchedule = active && !suppressedDelivered;
  if (!shouldSchedule) {
    return { type: 'cancel', identifier, category, tripId, suppressedDelivered };
  }
  return {
    type: 'schedule',
    identifier,
    category,
    tripId,
    fireAt: Math.max(now, rawFireAt),
    ...message,
  };
}

// Warn that a trip is not cached for offline use, timed to the approach of its
// departure. Scheduled only while the departure is still in the future and the
// data is not yet cached; cancelled once the cache is ready or the date passes.
function offlineNotReadyAction(
  trip: TripNotificationInput,
  prefs: Record<LocalCategory, boolean>,
  messages: LocalMessages,
  now: number,
  delivered: ReadonlySet<string>,
): NotificationAction {
  const departure = trip.startDate ? Date.parse(trip.startDate) : NaN;
  const active =
    prefs.offlineNotReady &&
    !trip.offlineReady &&
    Number.isFinite(departure) &&
    departure > now;
  return buildAction(
    'offlineNotReady',
    trip.id,
    active,
    departure - OFFLINE_LEAD_MS,
    now,
    messages.offlineNotReady,
    delivered,
  );
}

// Remind the rider to set dates on a trip created without any. Scheduled while the
// trip has no startDate; cancelled the moment a date is defined.
function tripNoDateAction(
  trip: TripNotificationInput,
  prefs: Record<LocalCategory, boolean>,
  messages: LocalMessages,
  now: number,
  delivered: ReadonlySet<string>,
): NotificationAction {
  const active = prefs.tripNoDate && !trip.startDate;
  const created = Date.parse(trip.createdAt);
  const base = Number.isFinite(created) ? created : now;
  return buildAction(
    'tripNoDate',
    trip.id,
    active,
    base + NO_DATE_DELAY_MS,
    now,
    messages.tripNoDate,
    delivered,
  );
}

/**
 * One schedule-or-cancel action per (category, trip). Actions are scoped to the
 * trips passed in, so the reconcile layer never touches notifications for trips it
 * has not been told about (the list is paginated). `delivered` holds the ids of
 * one-shots that already fired, so a past-due reminder is not re-scheduled.
 */
export function planLocalNotifications(input: {
  trips: TripNotificationInput[];
  prefs: Record<LocalCategory, boolean>;
  messages: LocalMessages;
  now?: number;
  delivered?: ReadonlySet<string>;
}): NotificationAction[] {
  const now = input.now ?? Date.now();
  const delivered = input.delivered ?? new Set<string>();
  return input.trips.flatMap((trip) => [
    offlineNotReadyAction(trip, input.prefs, input.messages, now, delivered),
    tripNoDateAction(trip, input.prefs, input.messages, now, delivered),
  ]);
}
