import { Stack } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button, Card, Input, Screen } from '../../src/components/ui';
import { Mail } from '../../src/components/ui/icons';
import { useTheme } from '../../src/theme';
import { useEmailChange } from '../../src/hooks/use-email-change';

export default function AccountEmail() {
  const { t } = useTranslation();
  const theme = useTheme();
  const { busy, sent, error, submit, reset } = useEmailChange();
  const [email, setEmail] = useState('');

  return (
    <Screen scroll>
      <Stack.Screen options={{ headerShown: true, title: t('account.emailTitle') }} />
      <View style={{ gap: theme.spacing.base }}>
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 14,
            lineHeight: 20,
          }}
        >
          {t('account.emailChange.description')}
        </Text>

        {sent ? (
          <Card style={{ flexDirection: 'row', gap: theme.spacing.md, alignItems: 'flex-start' }}>
            <Mail color={theme.colors.accentInk} size={20} />
            <View style={{ flex: 1, gap: 2 }}>
              <Text
                style={{
                  color: theme.colors.foreground,
                  fontFamily: theme.fonts.sansSemibold,
                  fontSize: 15,
                }}
              >
                {t('account.emailChange.sentTitle')}
              </Text>
              <Text
                style={{
                  color: theme.colors.mutedForeground,
                  fontFamily: theme.fonts.sans,
                  fontSize: 13,
                  lineHeight: 18,
                }}
              >
                {t('account.emailChange.sent', { email: email.trim() })}
              </Text>
            </View>
          </Card>
        ) : (
          <>
            <Input
              label={t('account.emailChange.label')}
              placeholder={t('account.emailChange.placeholder')}
              value={email}
              onChangeText={(v) => {
                setEmail(v);
                if (error) reset();
              }}
              error={error ? t('account.emailChange.error') : undefined}
              leadingIcon={<Mail color={theme.colors.mutedForeground} size={18} />}
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="email-address"
            />
            <Button
              label={busy ? t('account.emailChange.submitting') : t('account.emailChange.submit')}
              onPress={() => void submit(email.trim())}
              loading={busy}
              disabled={busy || email.trim().length === 0}
              fullWidth
              size="lg"
            />
          </>
        )}
      </View>
    </Screen>
  );
}
