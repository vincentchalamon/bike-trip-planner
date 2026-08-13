import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { LoadingState, Screen } from '../../../src/components/ui';
import { useAuth } from '../../../src/auth/store';

// Handles the magic-link verification deep link, for both forms:
//   App Link      : https://<host>/auth/verify/<token>
//   custom scheme : biketripplanner://auth/verify/<token>
// Expo Router maps both onto this file route (path /auth/verify/:token), so the
// token exchange runs here instead of racing the router from a Linking hook.
export default function VerifyScreen() {
  const { token } = useLocalSearchParams<{ token: string }>();
  const { verify } = useAuth();
  const { t } = useTranslation();
  const router = useRouter();
  const handled = useRef(false);

  useEffect(() => {
    if (handled.current || typeof token !== 'string' || token.length === 0) {
      return;
    }
    handled.current = true;
    void (async () => {
      try {
        const ok = await verify(token);
        router.replace(ok ? '/(tabs)' : '/login');
      } catch {
        router.replace('/login');
      }
    })();
  }, [token, verify, router]);

  return (
    <Screen padded={false}>
      <LoadingState label={t('auth.verifying')} />
    </Screen>
  );
}
