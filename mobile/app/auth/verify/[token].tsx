import { useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle } from '../../../src/components/ui/icons';
import { ErrorState, LoadingState, Screen } from '../../../src/components/ui';
import { useTheme } from '../../../src/theme';
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
  const theme = useTheme();
  const router = useRouter();
  const handled = useRef(false);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (handled.current || typeof token !== 'string' || token.length === 0) {
      return;
    }
    handled.current = true;
    void (async () => {
      try {
        const ok = await verify(token);
        if (ok) {
          router.replace('/(tabs)');
        } else {
          setFailed(true);
        }
      } catch {
        setFailed(true);
      }
    })();
  }, [token, verify, router]);

  if (failed) {
    return (
      <Screen>
        <ErrorState
          icon={<AlertTriangle color={theme.colors.destructive} size={40} />}
          title={t('auth.expiredTitle')}
          description={t('auth.expiredBody')}
          retryLabel={t('auth.requestNew')}
          onRetry={() => router.replace('/login')}
        />
      </Screen>
    );
  }

  return (
    <Screen padded={false}>
      <LoadingState label={t('auth.verifying')} />
    </Screen>
  );
}
