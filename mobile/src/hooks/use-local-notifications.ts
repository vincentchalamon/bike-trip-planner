import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import type { TripListItem } from '../api/trips';
import { useNotificationPrefs } from '../store/notification-prefs';
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

  // The prefs store hydrates on the notifications screen; hydrate it here too so the
  // schedule honours persisted toggles even if that screen was never opened.
  useEffect(() => void load(), [load]);

  useEffect(() => {
    if (!hydrated) return;
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
    void reconcileLocalNotifications({
      trips: inputs,
      prefs: {
        offlineNotReady: enabled.offlineNotReady,
        tripNoDate: enabled.tripNoDate,
      },
      messages,
    });
  }, [trips, enabled.offlineNotReady, enabled.tripNoDate, hydrated, t]);
}
