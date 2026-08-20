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
// refreshed via GET /users/me (best-effort) so the account screen reflects the
// new address; a refresh failure never masks the successful verification.
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
      // Only a verify failure is a real error. A post-verify refreshEmail() throw
      // (network) must NOT surface as "verification failed" — the email is already
      // changed server-side; the stale display self-heals on the next mount.
      let verified: boolean;
      try {
        verified = await verifyEmailChange(token);
      } catch {
        setFailed(true);
        return;
      }
      if (!verified) {
        setFailed(true);
        return;
      }
      try {
        await refreshEmail();
      } catch {
        // best-effort, ignore
      }
      router.replace('/(tabs)/account');
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
