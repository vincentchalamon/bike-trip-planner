/// <reference types="jest" />
import {
  NO_DATE_DELAY_MS,
  OFFLINE_LEAD_MS,
  notificationIdentifier,
  planLocalNotifications,
  type LocalCategory,
  type LocalMessages,
  type NotificationAction,
  type TripNotificationInput,
} from './plan';

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

function plan(
  trip: Partial<TripNotificationInput>,
  prefs: Record<LocalCategory, boolean> = ALL_ON,
  delivered: ReadonlySet<string> = new Set<string>(),
): NotificationAction[] {
  const full: TripNotificationInput = {
    id: 't1',
    startDate: null,
    createdAt: new Date(NOW).toISOString(),
    offlineReady: false,
    ...trip,
  };
  return planLocalNotifications({
    trips: [full],
    prefs,
    messages: MESSAGES,
    now: NOW,
    delivered,
  });
}

function action(actions: NotificationAction[], category: LocalCategory): NotificationAction {
  const found = actions.find((a) => a.identifier === notificationIdentifier(category, 't1'));
  if (!found) throw new Error(`no action for ${category}`);
  return found;
}

describe('planLocalNotifications — tripNoDate', () => {
  it('schedules a reminder 2 days after creation when the trip has no date', () => {
    const a = action(plan({ startDate: null }), 'tripNoDate');
    expect(a.type).toBe('schedule');
    if (a.type === 'schedule') {
      expect(a.fireAt).toBe(NOW + NO_DATE_DELAY_MS);
      expect(a.title).toBe('nodate-title');
      expect(a.body).toBe('nodate-body');
    }
  });

  it('cancels once a date is defined (condition resolved)', () => {
    const a = action(plan({ startDate: '2026-09-10T00:00:00Z' }), 'tripNoDate');
    expect(a.type).toBe('cancel');
  });

  it('does not schedule when the toggle is OFF', () => {
    const a = action(
      plan({ startDate: null }, { offlineNotReady: true, tripNoDate: false }),
      'tripNoDate',
    );
    expect(a.type).toBe('cancel');
  });

  it('clamps the fire date to now when creation is already older than the delay', () => {
    const a = action(
      plan({ startDate: null, createdAt: new Date(NOW - 10 * DAY).toISOString() }),
      'tripNoDate',
    );
    expect(a.type).toBe('schedule');
    if (a.type === 'schedule') expect(a.fireAt).toBe(NOW);
  });
});

describe('planLocalNotifications — offlineNotReady', () => {
  it('schedules ahead of a future departure when not cached', () => {
    const departure = NOW + 10 * DAY;
    const a = action(
      plan({ startDate: new Date(departure).toISOString(), offlineReady: false }),
      'offlineNotReady',
    );
    expect(a.type).toBe('schedule');
    if (a.type === 'schedule') {
      expect(a.fireAt).toBe(departure - OFFLINE_LEAD_MS);
      expect(a.title).toBe('offline-title');
    }
  });

  it('clamps the fire date to now when departure is within the lead window', () => {
    const departure = NOW + 1 * DAY; // closer than the 2-day lead
    const a = action(
      plan({ startDate: new Date(departure).toISOString(), offlineReady: false }),
      'offlineNotReady',
    );
    expect(a.type).toBe('schedule');
    if (a.type === 'schedule') expect(a.fireAt).toBe(NOW);
  });

  it('cancels once the cache is ready (condition resolved)', () => {
    const a = action(
      plan({ startDate: new Date(NOW + 10 * DAY).toISOString(), offlineReady: true }),
      'offlineNotReady',
    );
    expect(a.type).toBe('cancel');
  });

  it('cancels once the departure date has passed', () => {
    const a = action(
      plan({ startDate: new Date(NOW - 1 * DAY).toISOString(), offlineReady: false }),
      'offlineNotReady',
    );
    expect(a.type).toBe('cancel');
  });

  it('cancels when the trip has no date at all', () => {
    const a = action(plan({ startDate: null }), 'offlineNotReady');
    expect(a.type).toBe('cancel');
  });

  it('does not schedule when the toggle is OFF', () => {
    const a = action(
      plan(
        { startDate: new Date(NOW + 10 * DAY).toISOString(), offlineReady: false },
        { offlineNotReady: false, tripNoDate: true },
      ),
      'offlineNotReady',
    );
    expect(a.type).toBe('cancel');
  });
});

describe('planLocalNotifications — delivered one-shot guard', () => {
  const NODATE_ID = notificationIdentifier('tripNoDate', 't1');
  const OFFLINE_ID = notificationIdentifier('offlineNotReady', 't1');

  it('does not re-schedule a past-due reminder that already fired (condition still true)', () => {
    // Created 10 days ago, still dateless → rawFireAt is in the past. Once delivered
    // it must not be re-scheduled even though the "no date" condition still holds.
    const a = action(
      plan(
        { startDate: null, createdAt: new Date(NOW - 10 * DAY).toISOString() },
        ALL_ON,
        new Set([NODATE_ID]),
      ),
      'tripNoDate',
    );
    expect(a.type).toBe('cancel');
  });

  it('re-arms a delivered offline reminder once its fire date is back in the future', () => {
    // Departure far out → rawFireAt > now: a fresh future instance, so a stale
    // delivered mark must not suppress it (reconcile clears the mark).
    const a = action(
      plan(
        { startDate: new Date(NOW + 10 * DAY).toISOString(), offlineReady: false },
        ALL_ON,
        new Set([OFFLINE_ID]),
      ),
      'offlineNotReady',
    );
    expect(a.type).toBe('schedule');
    if (a.type === 'schedule') expect(a.fireAt).toBe(NOW + 10 * DAY - OFFLINE_LEAD_MS);
  });

  it('still schedules a past-due reminder that has NOT been delivered yet', () => {
    const a = action(
      plan(
        { startDate: null, createdAt: new Date(NOW - 10 * DAY).toISOString() },
        ALL_ON,
        new Set<string>(),
      ),
      'tripNoDate',
    );
    expect(a.type).toBe('schedule');
    if (a.type === 'schedule') expect(a.fireAt).toBe(NOW);
  });
});
