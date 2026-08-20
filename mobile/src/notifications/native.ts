// Thin wrappers over expo-notifications' scheduling API — the only impure part of
// the local-notification pipeline, kept isolated so reconcile.ts can be tested by
// mocking just this module's calls.
import * as Notifications from 'expo-notifications';
import { SchedulableTriggerInputTypes } from 'expo-notifications';
import type { ScheduleAction } from './plan';

// Read the epoch-ms fire time out of a scheduled notification's DATE trigger.
// getAllScheduledNotificationsAsync() returns the trigger for each request, so a
// DATE trigger carries back its `date` (number | Date). Returns null when the fire
// time cannot be read (non-DATE / unknown trigger) so reconcile leaves it untouched.
function dateTriggerFireAt(trigger: unknown): number | null {
  if (!trigger || typeof trigger !== 'object') return null;
  const t = trigger as { type?: unknown; date?: unknown };
  if (t.type !== 'date') return null;
  if (typeof t.date === 'number') return t.date;
  if (t.date instanceof Date) return t.date.getTime();
  if (typeof t.date === 'string') {
    const parsed = Date.parse(t.date);
    return Number.isNaN(parsed) ? null : parsed;
  }
  return null;
}

/**
 * Every scheduled notification keyed by identifier, mapped to its DATE-trigger fire
 * time (epoch ms) or null when unreadable. Reconcile uses the key set to tell
 * scheduled from not, and the value to detect a fire time that has since moved.
 */
export async function getScheduledFireTimes(): Promise<Map<string, number | null>> {
  const scheduled = await Notifications.getAllScheduledNotificationsAsync();
  return new Map(scheduled.map((request) => [request.identifier, dateTriggerFireAt(request.trigger)]));
}

/** Schedule (or, given the stable identifier, replace) a dated local notification. */
export async function scheduleLocalNotification(action: ScheduleAction): Promise<void> {
  await Notifications.scheduleNotificationAsync({
    identifier: action.identifier,
    content: { title: action.title, body: action.body },
    trigger: { type: SchedulableTriggerInputTypes.DATE, date: action.fireAt },
  });
}

export async function cancelLocalNotification(identifier: string): Promise<void> {
  await Notifications.cancelScheduledNotificationAsync(identifier);
}
