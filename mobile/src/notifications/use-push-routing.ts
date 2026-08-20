import { useEffect } from 'react';
import { useRouter } from 'expo-router';
import * as Notifications from 'expo-notifications';
import { configurePushPresentation } from './push';
import { resolvePushRoute, type PushData } from './push-routing';

// Install the notification-tap listeners and navigate to the mapped route.
// Handles both the warm path (tap while running/backgrounded) and the cold
// path (app opened by tapping a notification while it was killed). Also sets the
// foreground presentation handler (owned by push.ts). Mount once, near the root.
export function usePushRouting(): void {
  const router = useRouter();

  useEffect(() => {
    configurePushPresentation();

    const go = (data: unknown) => {
      const route = resolvePushRoute(data as PushData);
      if (route) router.push(route);
    };

    // Cold start: the notification whose tap launched the app.
    void Notifications.getLastNotificationResponseAsync().then((response) => {
      if (response) go(response.notification.request.content.data);
    });

    // Warm: tap while the app is already running.
    const sub = Notifications.addNotificationResponseReceivedListener((response) => {
      go(response.notification.request.content.data);
    });

    return () => sub.remove();
  }, [router]);
}
