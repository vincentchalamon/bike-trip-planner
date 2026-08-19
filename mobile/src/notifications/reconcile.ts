// Applies the pure plan (plan.ts) to the device via the native wrappers
// (native.ts). Idempotent: a desired notification already scheduled is left as is,
// and a cancel only fires when the notification actually exists — so re-running on
// every trip-data change is cheap and never duplicates.
import {
  planLocalNotifications,
  type LocalCategory,
  type LocalMessages,
  type TripNotificationInput,
} from './plan';
import {
  cancelLocalNotification,
  getScheduledIdentifiers,
  scheduleLocalNotification,
} from './native';

export async function reconcileLocalNotifications(input: {
  trips: TripNotificationInput[];
  prefs: Record<LocalCategory, boolean>;
  messages: LocalMessages;
  now?: number;
}): Promise<void> {
  const actions = planLocalNotifications(input);
  const scheduled = await getScheduledIdentifiers();
  for (const action of actions) {
    if (action.type === 'schedule') {
      if (!scheduled.has(action.identifier)) {
        await scheduleLocalNotification(action);
      }
    } else if (scheduled.has(action.identifier)) {
      await cancelLocalNotification(action.identifier);
    }
  }
}
