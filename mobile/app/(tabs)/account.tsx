import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button, Screen } from '../../src/components/ui';
import { LocaleSwitcher } from '../../src/i18n/LocaleSwitcher';
import { useTheme } from '../../src/theme';
import { useAuth } from '../../src/auth/store';

export default function Account() {
  const { t } = useTranslation();
  const theme = useTheme();
  const { logout } = useAuth();

  return (
    <Screen>
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.serif,
          fontSize: 26,
          marginBottom: theme.spacing.lg,
        }}
      >
        {t('account.title')}
      </Text>

      <Text
        style={{
          color: theme.colors.mutedForeground,
          fontFamily: theme.fonts.sansMedium,
          fontSize: 14,
          marginBottom: theme.spacing.sm,
        }}
      >
        {t('account.language')}
      </Text>
      <LocaleSwitcher />

      <View style={{ flex: 1 }} />
      <Button label={t('account.logout')} variant="secondary" onPress={() => void logout()} />
    </Screen>
  );
}
