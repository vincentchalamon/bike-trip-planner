/// <reference types="jest" />
import * as Notifications from 'expo-notifications';
import { reconcileLocalNotifications } from './reconcile';
import {
  notificationIdentifier,
  type LocalCategory,
  type LocalMessages,
  type TripNotificationInput,
} from './plan';

jest.mock('expo-notifications', () => ({
  SchedulableTriggerInputTypes: { DATE: 'date' },
  getAllScheduledNotificationsAsync: jest.fn(),
  scheduleNotificationAsync: jest.fn(),
  cancelScheduledNotificationAsync: jest.fn(),
}));

const getAll = Notifications.getAllScheduledNotificationsAsync as jest.Mock;
const schedule = Notifications.scheduleNotificationAsync as jest.Mock;
const cancel = Notifications.cancelScheduledNotificationAsync as jest.Mock;

const NOW = Date.parse('2026-08-19T00:00:00Z');
const DAY = 24 * 60 * 60 * 1000;

const MESSAGES: LocalMessages = {
  offlineNotReady: { title: 'offline-title', body: 'offline-body' },
  tripNoDate: { title: 'nodate-title', body: 'nodate-body' },
};

const ALL_ON: Record<LocalCategory, boolean> = {
  offlineNotReady: true,
  tripNoDate: true,
};

function scheduledWith(identifiers: string[]): void {
  getAll.mockResolvedValue(identifiers.map((identifier) => ({ identifier })));
}

// Scheduled notifications carrying their DATE-trigger fire time, in the shape the
// native layer serializes on READ (Android: {type:'date', value:<epoch ms>}, see
// NotificationTriggers.kt) — NOT the {type:'date', date} input shape — so this
// exercises dateTriggerFireAt against the real device response.
function scheduledWithDates(entries: Record<string, number>): void {
  getAll.mockResolvedValue(
    Object.entries(entries).map(([identifier, value]) => ({
      identifier,
      trigger: { type: 'date', repeats: false, value },
    })),
  );
}

const markDelivered = jest.fn();
const clearDelivered = jest.fn();

async function run(
  trip: Partial<TripNotificationInput>,
  prefs: Record<LocalCategory, boolean> = ALL_ON,
  delivered: ReadonlySet<string> = new Set<string>(),
): Promise<void> {
  const full: TripNotificationInput = {
    id: 't1',
    startDate: null,
    createdAt: new Date(NOW).toISOString(),
    offlineReady: false,
    ...trip,
  };
  await reconcileLocalNotifications({
    trips: [full],
    prefs,
    messages: MESSAGES,
    now: NOW,
    delivered,
    markDelivered,
    clearDelivered,
  });
}

const NODATE_ID = notificationIdentifier('tripNoDate', 't1');
const OFFLINE_ID = notificationIdentifier('offlineNotReady', 't1');

beforeEach(() => {
  getAll.mockReset();
  schedule.mockReset();
  cancel.mockReset();
  markDelivered.mockReset();
  clearDelivered.mockReset();
});

describe('reconcileLocalNotifications', () => {
  it('schedules a desired notification that is not yet scheduled', async () => {
    scheduledWith([]);
    await run({ startDate: null }); // trip without date → tripNoDate desired
    expect(schedule).toHaveBeenCalledWith(
      expect.objectContaining({
        identifier: NODATE_ID,
        content: { title: 'nodate-title', body: 'nodate-body' },
        trigger: { type: 'date', date: NOW + 2 * DAY },
      }),
    );
  });

  it('does not re-schedule a notification that already exists (idempotent)', async () => {
    scheduledWith([NODATE_ID]);
    await run({ startDate: null });
    expect(schedule).not.toHaveBeenCalledWith(
      expect.objectContaining({ identifier: NODATE_ID }),
    );
  });

  it('cancels a scheduled notification once its condition is resolved', async () => {
    scheduledWith([NODATE_ID]);
    // A date is now defined → tripNoDate condition resolved.
    await run({ startDate: '2026-09-10T00:00:00Z' });
    expect(cancel).toHaveBeenCalledWith(NODATE_ID);
  });

  it('does not cancel a condition-resolved notification that was never scheduled', async () => {
    scheduledWith([]);
    await run({ startDate: '2026-09-10T00:00:00Z' });
    expect(cancel).not.toHaveBeenCalled();
  });

  it('with the toggle OFF, cancels an already-scheduled notification and schedules nothing', async () => {
    scheduledWith([NODATE_ID]);
    await run({ startDate: null }, { offlineNotReady: true, tripNoDate: false });
    expect(cancel).toHaveBeenCalledWith(NODATE_ID);
    expect(schedule).not.toHaveBeenCalled();
  });

  it('cancels offline-not-ready once the cache is ready', async () => {
    scheduledWith([OFFLINE_ID]);
    await run({
      startDate: new Date(NOW + 10 * DAY).toISOString(),
      offlineReady: true,
    });
    expect(cancel).toHaveBeenCalledWith(OFFLINE_ID);
  });

  it('leaves a scheduled notification for a trip absent from the list (paged/filtered out)', async () => {
    // reconcile only ever sees a partial list (paginated + search-filtered), so it
    // must not cancel a reminder just because its trip is not in the current page:
    // that would fire on plain browsing/search. Removal is handled at the delete site.
    const ABSENT_ID = notificationIdentifier('tripNoDate', 't2');
    scheduledWith([ABSENT_ID]);
    await run({ startDate: '2026-09-10T00:00:00Z' });
    expect(cancel).not.toHaveBeenCalledWith(ABSENT_ID);
    expect(clearDelivered).not.toHaveBeenCalledWith(ABSENT_ID);
  });

  it('marks a past-due one-shot delivered when it fires immediately', async () => {
    scheduledWith([]);
    // Created 10 days ago, still dateless → fireAt clamps to now (fires at once).
    await run({ startDate: null, createdAt: new Date(NOW - 10 * DAY).toISOString() });
    expect(schedule).toHaveBeenCalledWith(
      expect.objectContaining({ identifier: NODATE_ID, trigger: { type: 'date', date: NOW } }),
    );
    expect(markDelivered).toHaveBeenCalledWith(NODATE_ID);
  });

  it('does not re-fire a past-due one-shot already delivered (condition still true)', async () => {
    scheduledWith([]); // fired → gone from the scheduled set
    await run(
      { startDate: null, createdAt: new Date(NOW - 10 * DAY).toISOString() },
      ALL_ON,
      new Set([NODATE_ID]),
    );
    expect(schedule).not.toHaveBeenCalledWith(
      expect.objectContaining({ identifier: NODATE_ID }),
    );
    expect(markDelivered).not.toHaveBeenCalled();
  });

  it('clears the delivered mark when re-arming a future instance', async () => {
    scheduledWith([]);
    // Departure far out → fireAt in the future: a fresh instance re-arms.
    await run(
      { startDate: new Date(NOW + 10 * DAY).toISOString(), offlineReady: false },
      ALL_ON,
      new Set([OFFLINE_ID]),
    );
    expect(schedule).toHaveBeenCalledWith(
      expect.objectContaining({ identifier: OFFLINE_ID }),
    );
    expect(clearDelivered).toHaveBeenCalledWith(OFFLINE_ID);
    expect(markDelivered).not.toHaveBeenCalled();
  });

  it('clears the delivered mark when a scheduled reminder condition is resolved', async () => {
    scheduledWith([NODATE_ID]);
    await run({ startDate: '2026-09-10T00:00:00Z' }); // date set → tripNoDate resolved
    expect(cancel).toHaveBeenCalledWith(NODATE_ID);
    expect(clearDelivered).toHaveBeenCalledWith(NODATE_ID);
  });

  it('clears the delivered mark of a past-due one-shot that fired then resolved', async () => {
    // The one-shot already fired (gone from the scheduled set) and is still marked
    // delivered; its condition now resolves. Nothing to cancel, but the delivered
    // entry must be reclaimed so the persisted set does not leak forever.
    scheduledWith([]);
    await run(
      { startDate: '2026-09-10T00:00:00Z' },
      ALL_ON,
      new Set([NODATE_ID]),
    );
    expect(cancel).not.toHaveBeenCalled();
    expect(clearDelivered).toHaveBeenCalledWith(NODATE_ID);
  });

  it('reschedules an already-scheduled reminder when its desired fire time moved', async () => {
    // Scheduled at NOW+5d, but the dateless trip was created at NOW so the desired
    // reminder time is NOW+2d — the stale schedule must be moved.
    scheduledWithDates({ [NODATE_ID]: NOW + 5 * DAY });
    await run({ startDate: null });
    expect(cancel).toHaveBeenCalledWith(NODATE_ID);
    expect(schedule).toHaveBeenCalledWith(
      expect.objectContaining({
        identifier: NODATE_ID,
        trigger: { type: 'date', date: NOW + 2 * DAY },
      }),
    );
  });

  it('leaves an already-scheduled reminder whose fire time is unchanged', async () => {
    scheduledWithDates({ [NODATE_ID]: NOW + 2 * DAY }); // equals the desired time
    await run({ startDate: null });
    expect(cancel).not.toHaveBeenCalled();
    expect(schedule).not.toHaveBeenCalled();
  });
});
