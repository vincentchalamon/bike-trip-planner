import { Text, View } from 'react-native';
import { Stack } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Screen } from '../ui';
import { useTheme } from '../../theme';

type AccountStubKey =
  | 'account.emailTitle'
  | 'account.notificationsTitle'
  | 'account.exportTitle'
  | 'account.deleteTitle'
  | 'account.faqTitle'
  | 'account.legalTitle'
  | 'account.privacyTitle';

// Placeholder for the account sub-routes. Each is replaced by its own sibling
// issue (#1117 email, #1118 export/delete, #1119 faq/legal/privacy, #1120
// notifications); this foundation ships the navigable shells.
export function AccountStub({ titleKey }: { titleKey: AccountStubKey }) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <Screen>
      <Stack.Screen options={{ headerShown: true, title: t(titleKey) }} />
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}>
        <Text
          style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sans, fontSize: 15 }}
        >
          {t('account.comingSoon')}
        </Text>
      </View>
    </Screen>
  );
}
