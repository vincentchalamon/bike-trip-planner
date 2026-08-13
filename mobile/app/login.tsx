import { Redirect } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button, Input, Screen } from '../src/components/ui';
import { useTheme } from '../src/theme';
import { useAuth } from '../src/auth/store';

export default function Login() {
  const { t } = useTranslation();
  const theme = useTheme();
  const { authenticated, requestLink } = useAuth();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (authenticated) {
    return <Redirect href="/(tabs)" />;
  }

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const ok = await requestLink(email.trim());
      if (ok) {
        setSent(true);
      } else {
        setError(t('login.error'));
      }
    } catch {
      setError(t('login.error'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen style={{ flex: 1, justifyContent: 'center', gap: theme.spacing.md }}>
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.serif,
          fontSize: 28,
          textAlign: 'center',
          marginBottom: theme.spacing.lg,
        }}
      >
        {t('login.brand')}
      </Text>
      {sent ? (
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 16,
            textAlign: 'center',
          }}
        >
          {t('login.sent', { email })}
        </Text>
      ) : (
        <>
          <Input
            label={t('login.emailLabel')}
            value={email}
            onChangeText={setEmail}
            error={error ?? undefined}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            placeholder={t('login.emailPlaceholder')}
          />
          <Button
            label={busy ? t('login.submitting') : t('login.submit')}
            onPress={() => void submit()}
            loading={busy}
            disabled={busy || !email}
            fullWidth
          />
        </>
      )}
    </Screen>
  );
}
