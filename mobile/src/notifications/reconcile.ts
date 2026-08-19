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
  // Ids of one-shots already delivered (so a past-due reminder is not re-fired).
  delivered: ReadonlySet<string>;
  // Persist a just-fired past-due one-shot; forget one re-armed for a future date.
  markDelivered: (id: string) => void;
  clearDelivered: (id: string) => void;
}): Promise<void> {
  const now = input.now ?? Date.now();
  const actions = planLocalNotifications({ ...input, now });
  const scheduled = await getScheduledIdentifiers();
  for (const action of actions) {
    if (action.type === 'schedule') {
      if (scheduled.has(action.identifier)) continue;
      await scheduleLocalNotification(action);
      // A clamped-to-now schedule fires immediately: record it so the next pass
      // (where it is gone from the scheduled set) does not re-fire it. A genuinely
      // future schedule re-arms a previously delivered id, so clear its mark.
      if (action.fireAt <= now) {
        input.markDelivered(action.identifier);
      } else {
        input.clearDelivered(action.identifier);
      }
    } else if (scheduled.has(action.identifier)) {
      await cancelLocalNotification(action.identifier);
    }
  }
}
