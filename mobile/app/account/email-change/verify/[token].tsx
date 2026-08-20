import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle } from '../../../../src/components/ui/icons';
import { ErrorState, LoadingState, Screen } from '../../../../src/components/ui';
import { useTheme } from '../../../../src/theme';
import { useAuth } from '../../../../src/auth/store';
import { verifyEmailChange } from '../../../../src/hooks/use-email-change';

// Handles the email-change confirmation deep link, for both forms:
//   App Link      : https://<host>/account/email-change/verify/<token>
//   custom scheme : biketripplanner://account/email-change/verify/<token>
// The path mirrors the backend link ({FRONTEND_URL}/account/email-change/verify/
// {token}, RequestEmailChangeProcessor) and the PWA route of the same name.
// The link is sent to the NEW address; opening it while authenticated commits the
// change (single-use token, atomic server-side). On success the account email is
// refreshed via GET /users/me so the account screen reflects the new address.
export default function VerifyEmailChangeScreen() {
  const { token } = useLocalSearchParams<{ token: string }>();
  const { refreshEmail } = useAuth();
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
        if (await verifyEmailChange(token)) {
          await refreshEmail();
          router.replace('/(tabs)/account');
        } else {
          setFailed(true);
        }
      } catch {
        setFailed(true);
      }
    })();
  }, [token, refreshEmail, router]);

  if (failed) {
    return (
      <Screen>
        <Stack.Screen options={{ headerShown: false }} />
        <ErrorState
          icon={<AlertTriangle color={theme.colors.destructive} size={40} />}
          title={t('account.emailChange.verifyFailedTitle')}
          description={t('account.emailChange.verifyFailedBody')}
          retryLabel={t('account.emailChange.backToAccount')}
          onRetry={() => router.replace('/(tabs)/account')}
        />
      </Screen>
    );
  }

  return (
    <Screen padded={false}>
      <Stack.Screen options={{ headerShown: false }} />
      <LoadingState label={t('account.emailChange.verifying')} />
    </Screen>
  );
}
