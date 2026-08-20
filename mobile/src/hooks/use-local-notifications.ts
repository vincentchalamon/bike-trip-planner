import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import type { TripListItem } from '../api/trips';
import { useNotificationPrefs } from '../store/notification-prefs';
import { useDeliveredNotifications } from '../store/delivered-notifications';
import { reconcileLocalNotifications } from '../notifications/reconcile';
import type { LocalMessages, TripNotificationInput } from '../notifications/plan';

// Re-derive and apply the local notification schedule whenever the trip list or the
// two local-category toggles change. Driven off the trips list because `createdAt`
// (needed by `tripNoDate`) is only exposed on the list item, not on /detail.
export function useLocalNotifications(trips: TripListItem[]): void {
  const { t } = useTranslation();
  const enabled = useNotificationPrefs((s) => s.enabled);
  const hydrated = useNotificationPrefs((s) => s.hydrated);
  const load = useNotificationPrefs((s) => s.load);
  const deliveredHydrated = useDeliveredNotifications((s) => s.hydrated);
  const loadDelivered = useDeliveredNotifications((s) => s.load);

  // The prefs store hydrates on the notifications screen; hydrate it here too so the
  // schedule honours persisted toggles even if that screen was never opened. The
  // delivered set must also be hydrated before reconciling, else a past-due one-shot
  // would look un-delivered on cold start and re-fire.
  useEffect(() => void load(), [load]);
  useEffect(() => void loadDelivered(), [loadDelivered]);

  useEffect(() => {
    if (!hydrated || !deliveredHydrated) return;
    const inputs: TripNotificationInput[] = trips
      .filter((trip) => Boolean(trip.id) && Boolean(trip.createdAt))
      .map((trip) => ({
        id: trip.id as string,
        startDate: trip.startDate ?? null,
        createdAt: trip.createdAt as string,
        // No on-device trip cache exists yet (offline-store tracks connectivity
        // only); until an offline-sync producer lands, no trip is cached.
        offlineReady: false,
      }));
    const messages: LocalMessages = {
      offlineNotReady: {
        title: t('notifications.offlineNotReadyNotifTitle'),
        body: t('notifications.offlineNotReadyNotifBody'),
      },
      tripNoDate: {
        title: t('notifications.tripNoDateNotifTitle'),
        body: t('notifications.tripNoDateNotifBody'),
      },
    };
    // Read the delivered set and its mutators imperatively, NOT as reactive deps:
    // reconcile calls markDelivered/clearDelivered, which replace the Set in the
    // store. If `delivered` were a dependency, that mutation would re-run this very
    // effect right after a past-due schedule — the id now looks delivered, the
    // action flips to `cancel`, and the notification is cancelled before the OS ever
    // presents it. getState() takes a one-shot snapshot with no feedback loop.
    const { delivered, markDelivered, clearDelivered } =
      useDeliveredNotifications.getState();
    void reconcileLocalNotifications({
      trips: inputs,
      prefs: {
        offlineNotReady: enabled.offlineNotReady,
        tripNoDate: enabled.tripNoDate,
      },
      messages,
      delivered,
      markDelivered,
      clearDelivered,
    });
  }, [trips, enabled.offlineNotReady, enabled.tripNoDate, hydrated, deliveredHydrated, t]);
}
