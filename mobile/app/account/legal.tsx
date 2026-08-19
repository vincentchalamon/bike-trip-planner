import { Text } from 'react-native';
import { Stack } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Screen } from '../../src/components/ui';
import { ContentSection } from '../../src/components/account/StaticContent';
import { useTheme } from '../../src/theme';

export default function AccountLegal() {
  const { t } = useTranslation();
  const theme = useTheme();

  return (
    <Screen scroll>
      <Stack.Screen options={{ headerShown: true, title: t('account.legalTitle') }} />
      <Text
        style={{
          color: theme.colors.mutedForeground,
          fontFamily: theme.fonts.sans,
          fontSize: 12,
          marginBottom: theme.spacing.md,
        }}
      >
        {t('account.legalContent.lastUpdated')}
      </Text>
      <ContentSection title={t('account.legalContent.publisherTitle')} body={t('account.legalContent.publisherBody')} />
      <ContentSection title={t('account.legalContent.hostTitle')} body={t('account.legalContent.hostBody')} />
      <ContentSection title={t('account.legalContent.contactTitle')} body={t('account.legalContent.contactBody')} />
      <ContentSection title={t('account.legalContent.ipTitle')} body={t('account.legalContent.ipBody')} />
    </Screen>
  );
}
