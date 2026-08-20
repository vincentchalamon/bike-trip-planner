// Thin wrappers over expo-notifications' scheduling API — the only impure part of
// the local-notification pipeline, kept isolated so reconcile.ts can be tested by
// mocking just this module's calls.
import * as Notifications from 'expo-notifications';
import { SchedulableTriggerInputTypes } from 'expo-notifications';
import type { ScheduleAction } from './plan';

// Read the epoch-ms fire time out of a scheduled notification's DATE trigger.
// getAllScheduledNotificationsAsync() hands back the trigger as the native layer
// serializes it on READ, which is NOT the {type:'date', date} *input* shape passed
// to scheduleNotificationAsync: on Android a DATE trigger reads back as
// {type:'date', value:<epoch ms>} (expo-notifications NotificationTriggers.kt), and
// the JS scheduler normalizes the input to {type:'date', timestamp}. Accept every
// field that carries the instant. Returns null when unreadable (e.g. iOS maps a
// DATE input to a calendar trigger, which has no single timestamp), so reconcile
// simply leaves that reminder untouched rather than rescheduling on a false drift.
function dateTriggerFireAt(trigger: unknown): number | null {
  if (!trigger || typeof trigger !== 'object') return null;
  const t = trigger as { type?: unknown; value?: unknown; timestamp?: unknown; date?: unknown };
  if (t.type !== 'date') return null;
  return toEpochMs(t.value) ?? toEpochMs(t.timestamp) ?? toEpochMs(t.date);
}

function toEpochMs(value: unknown): number | null {
  if (typeof value === 'number') return value;
  if (value instanceof Date) return value.getTime();
  if (typeof value === 'string') {
    const parsed = Date.parse(value);
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
