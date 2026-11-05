import { useRouter } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Text, View } from 'react-native';
import { AlertTriangle } from '../../src/components/ui/icons';
import { Button, ErrorState, LoadingState, Screen } from '../../src/components/ui';
import { useTheme } from '../../src/theme';
import { useAuth } from '../../src/auth/store';
import { useLinkFragment } from '../../src/auth/link-fragment';

// Handles the magic-link verification deep link, for both forms:
//   App Link      : https://<host>/auth/verify#<token>
//   custom scheme : biketripplanner://auth/verify#<token>
// Expo Router maps both onto this file route (path /auth/verify), so the token
// exchange runs here instead of racing the router from a Linking hook. The token
// is in the fragment, which no server log sees (useLinkFragment).
//
// A link this device did not request, or any link while a session is open, is
// not consumed until the user has seen the account it opens (SEC-15).
export default function VerifyScreen() {
  const token = useLinkFragment();
  const { ready, verify, authenticated, email } = useAuth();
  const { t } = useTranslation();
  const theme = useTheme();
  const router = useRouter();
  const handled = useRef(false);
  const [failed, setFailed] = useState(false);
  const [pending, setPending] = useState(false);
  const [confirmEmail, setConfirmEmail] = useState<string | null>(null);

  const run = useCallback(
    async (confirm: boolean) => {
      setPending(true);
      try {
        const outcome = await verify(token, confirm);
        if (outcome.kind === 'ok') {
          router.replace('/(tabs)');
        } else if (outcome.kind === 'confirm') {
          // Already signed in to that very account: nothing to switch.
          if (authenticated && outcome.email === email) {
            router.replace('/(tabs)');
          } else {
            setConfirmEmail(outcome.email);
          }
        } else {
          setFailed(true);
        }
      } catch {
        setFailed(true);
      } finally {
        setPending(false);
      }
    },
    [token, verify, router, authenticated, email],
  );

  // Wait for the stored session: on a cold start from the link, verifying before
  // it is loaded would skip the account-switch protection.
  useEffect(() => {
    if (!ready || handled.current || token.length === 0) {
      return;
    }
    handled.current = true;
    void run(false);
  }, [ready, token, run]);

  // A link without a token cannot be verified: same screen as a rejected one.
  if (failed || token.length === 0) {
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

  if (confirmEmail) {
    const body = {
      color: theme.colors.foreground,
      fontFamily: theme.fonts.sans,
      fontSize: 15,
      lineHeight: 22,
    };
    return (
      <Screen>
        <View style={{ flex: 1, justifyContent: 'center', gap: theme.spacing.lg }}>
          <Text
            style={{ color: theme.colors.foreground, fontFamily: theme.fonts.serif, fontSize: 20 }}
          >
            {t('auth.confirmTitle')}
          </Text>
          <Text style={body} testID="verify-confirm-email">
            {t('auth.confirmAccount', { email: confirmEmail })}
          </Text>
          <Text style={{ ...body, color: theme.colors.mutedForeground }}>
            {authenticated
              ? t('auth.confirmSwitch', { email: email ?? '' })
              : t('auth.confirmWarning')}
          </Text>
          <Button
            label={t('auth.confirmContinue')}
            loading={pending}
            fullWidth
            onPress={() => void run(true)}
          />
          <Button
            label={t('auth.confirmCancel')}
            variant="outline"
            disabled={pending}
            fullWidth
            onPress={() => router.replace(authenticated ? '/(tabs)' : '/login')}
          />
        </View>
      </Screen>
    );
  }

  return (
    <Screen padded={false}>
      <LoadingState label={t('auth.verifying')} />
    </Screen>
  );
}
