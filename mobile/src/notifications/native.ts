// Thin wrappers over expo-notifications' scheduling API — the only impure part of
// the local-notification pipeline, kept isolated so reconcile.ts can be tested by
// mocking just this module's calls.
import * as Notifications from 'expo-notifications';
import { SchedulableTriggerInputTypes } from 'expo-notifications';
import type { ScheduleAction } from './plan';

/** Identifiers of every notification currently scheduled on the device. */
export async function getScheduledIdentifiers(): Promise<Set<string>> {
  const scheduled = await Notifications.getAllScheduledNotificationsAsync();
  return new Set(scheduled.map((request) => request.identifier));
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
