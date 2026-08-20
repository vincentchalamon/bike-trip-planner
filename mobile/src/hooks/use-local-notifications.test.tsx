/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement } from 'react';
import * as Notifications from 'expo-notifications';
import i18n from '../i18n';
import { NOTIFICATION_DEFAULTS, useNotificationPrefs } from '../store/notification-prefs';
import { useDeliveredNotifications } from '../store/delivered-notifications';
import { useLocalNotifications } from './use-local-notifications';
import type { TripListItem } from '../api/trips';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn().mockResolvedValue(null),
  setItemAsync: jest.fn(),
}));

// Stateful scheduled set: a just-scheduled id stays "pending" like a real past-due
// DATE trigger does until the OS presents it, so a spurious effect re-run can cancel
// it before presentation — that is exactly the regression under test.
const mockScheduledIds = new Set<string>();
jest.mock('expo-notifications', () => ({
  SchedulableTriggerInputTypes: { DATE: 'date' },
  getAllScheduledNotificationsAsync: jest.fn(async () =>
    [...mockScheduledIds].map((identifier) => ({ identifier })),
  ),
  scheduleNotificationAsync: jest.fn(async (req: { identifier: string }) => {
    mockScheduledIds.add(req.identifier);
    return req.identifier;
  }),
  cancelScheduledNotificationAsync: jest.fn(async (id: string) => {
    mockScheduledIds.delete(id);
  }),
}));

const schedule = Notifications.scheduleNotificationAsync as jest.Mock;
const cancel = Notifications.cancelScheduledNotificationAsync as jest.Mock;

const DAY = 24 * 60 * 60 * 1000;
const NODATE_ID = 'btp:tripNoDate:t1';

function Harness({ trips }: { trips: TripListItem[] }): null {
  useLocalNotifications(trips);
  return null;
}

async function flush(): Promise<void> {
  await act(async () => {
    for (let i = 0; i < 8; i += 1) await Promise.resolve();
  });
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  mockScheduledIds.clear();
  schedule.mockClear();
  cancel.mockClear();
  useNotificationPrefs.setState({ enabled: { ...NOTIFICATION_DEFAULTS }, hydrated: false });
  useDeliveredNotifications.setState({ delivered: new Set<string>(), hydrated: false });
});

describe('useLocalNotifications — no self-cancel loop', () => {
  it('does not cancel a freshly scheduled past-due one-shot when markDelivered re-renders', async () => {
    // Dateless trip created 10 days ago → the tripNoDate reminder is past-due and
    // fires immediately; reconcile then calls markDelivered, replacing the store Set.
    const trips = [
      {
        id: 't1',
        startDate: null,
        createdAt: new Date(Date.now() - 10 * DAY).toISOString(),
      } as TripListItem,
    ];

    await act(async () => {
      TestRenderer.create(createElement(Harness, { trips }));
    });
    await flush();
    // A second settle window: without the fix, the markDelivered-driven re-render
    // has had ample time to re-run the effect and cancel the pending notification.
    await flush();

    expect(schedule).toHaveBeenCalledWith(
      expect.objectContaining({ identifier: NODATE_ID }),
    );
    // The delivered mark must have been recorded (proves reconcile ran fully)...
    expect(useDeliveredNotifications.getState().delivered.has(NODATE_ID)).toBe(true);
    // ...yet the freshly scheduled, still-pending notification must NOT be cancelled.
    expect(cancel).not.toHaveBeenCalledWith(NODATE_ID);
    expect(mockScheduledIds.has(NODATE_ID)).toBe(true);
  });
});
