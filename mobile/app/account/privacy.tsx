import { Text } from 'react-native';
import { Stack } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Screen } from '../../src/components/ui';
import { ContentSection } from '../../src/components/account/StaticContent';
import { useTheme } from '../../src/theme';

export default function AccountPrivacy() {
  const { t } = useTranslation();
  const theme = useTheme();

  return (
    <Screen scroll>
      <Stack.Screen options={{ headerShown: true, title: t('account.privacyTitle') }} />
      <Text
        style={{
          color: theme.colors.mutedForeground,
          fontFamily: theme.fonts.sans,
          fontSize: 12,
          marginBottom: theme.spacing.md,
        }}
      >
        {t('account.privacyContent.lastUpdated')}
      </Text>
      <ContentSection
        title={t('account.privacyContent.controllerTitle')}
        body={t('account.privacyContent.controllerBody')}
      />
      <ContentSection title={t('account.privacyContent.basisTitle')} body={t('account.privacyContent.basisBody')} />
      <ContentSection
        title={t('account.privacyContent.purposesTitle')}
        body={t('account.privacyContent.purposesBody')}
      />
      <ContentSection title={t('account.privacyContent.dataTitle')} body={t('account.privacyContent.dataBody')} />
      <ContentSection
        title={t('account.privacyContent.retentionTitle')}
        body={t('account.privacyContent.retentionBody')}
      />
      <ContentSection title={t('account.privacyContent.rightsTitle')} body={t('account.privacyContent.rightsBody')} />
      <ContentSection
        title={t('account.privacyContent.processorsTitle')}
        body={t('account.privacyContent.processorsBody')}
      />
      <ContentSection
        title={t('account.privacyContent.analyticsTitle')}
        body={t('account.privacyContent.analyticsBody')}
      />
      <ContentSection
        title={t('account.privacyContent.contactTitle')}
        body={t('account.privacyContent.contactBody')}
      />
    </Screen>
  );
}
