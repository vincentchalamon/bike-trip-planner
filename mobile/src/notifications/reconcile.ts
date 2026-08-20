// Applies the pure plan (plan.ts) to the device via the native wrappers
// (native.ts). Idempotent: a desired notification already scheduled at the desired
// time is left as is, and a cancel only fires when the notification actually exists
// — so re-running on every trip-data change is cheap and never duplicates.
import {
  planLocalNotifications,
  type LocalCategory,
  type LocalMessages,
  type TripNotificationInput,
} from './plan';
import {
  cancelLocalNotification,
  getScheduledFireTimes,
  scheduleLocalNotification,
} from './native';

// A scheduled fire time this far (ms) from the desired one is treated as unchanged.
// Our fireAt values move by whole days when a trip date changes, so a minute of
// slack absorbs any storage/parse rounding without ever masking a real move.
const FIRE_TIME_TOLERANCE_MS = 60_000;

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
  const scheduled = await getScheduledFireTimes();

  // A clamped-to-now schedule fires immediately: record it so the next pass (where
  // it is gone from the scheduled set) does not re-fire it. A genuinely future
  // schedule re-arms a previously delivered id, so clear its mark.
  const commit = async (action: (typeof actions)[number] & { type: 'schedule' }): Promise<void> => {
    await scheduleLocalNotification(action);
    if (action.fireAt <= now) {
      input.markDelivered(action.identifier);
    } else {
      input.clearDelivered(action.identifier);
    }
  };

  for (const action of actions) {
    if (action.type === 'schedule') {
      if (!scheduled.has(action.identifier)) {
        await commit(action);
        continue;
      }
      // Already scheduled: a stored fire time that has since drifted (the trip's
      // date changed) must be re-applied — the identifier being present says
      // nothing about when it fires. Cancel then reschedule at the new time.
      const current = scheduled.get(action.identifier);
      if (typeof current === 'number' && Math.abs(current - action.fireAt) > FIRE_TIME_TOLERANCE_MS) {
        await cancelLocalNotification(action.identifier);
        await commit(action);
      }
    } else {
      // Drop the OS schedule if one is still pending.
      if (scheduled.has(action.identifier)) {
        await cancelLocalNotification(action.identifier);
      }
      // Forget the delivered mark ONLY on a genuine resolution (the condition is no
      // longer active). When the cancel is merely suppressing an already-fired
      // past-due one-shot whose condition is still active, keep the mark: clearing
      // it would make the next pass re-schedule and re-fire it (plan.ts). This is
      // also the only site that reclaims a fired one-shot's mark once its condition
      // does resolve (it is gone from `scheduled` by then).
      if (!action.suppressedDelivered) {
        input.clearDelivered(action.identifier);
      }
    }
  }
}
