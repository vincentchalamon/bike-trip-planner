import { useCallback, useState } from 'react';
import { Alert, Text, View } from 'react-native';
import { Stack, useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Screen } from '../../src/components/ui';
import { DeleteAccountForm } from '../../src/components/account/DeleteAccountForm';
import { deleteAccount } from '../../src/api/account';
import { useAuth } from '../../src/auth/store';
import { useTheme } from '../../src/theme';

export default function AccountDelete() {
  const { t } = useTranslation();
  const theme = useTheme();
  const router = useRouter();
  const { logout } = useAuth();
  const [loading, setLoading] = useState(false);

  const keyword = t('account.delete.keyword');

  const onConfirm = useCallback(async () => {
    setLoading(true);
    const ok = await deleteAccount();
    if (!ok) {
      setLoading(false);
      Alert.alert(t('account.deleteTitle'), t('account.delete.failed'));
      return;
    }
    await logout();
    router.replace('/login');
  }, [logout, router, t]);

  return (
    <Screen>
      <Stack.Screen options={{ headerShown: true, title: t('account.deleteTitle') }} />
      <View style={{ flex: 1, gap: theme.spacing.lg }}>
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sans,
            fontSize: 15,
            lineHeight: 22,
          }}
        >
          {t('account.delete.warningBefore')}
          <Text style={{ fontFamily: theme.fonts.sansSemibold }}>{keyword}</Text>
          {t('account.delete.warningAfter')}
        </Text>
        <DeleteAccountForm
          keyword={keyword}
          confirmLabel={t('account.delete.action')}
          placeholder={keyword}
          loading={loading}
          onConfirm={() => void onConfirm()}
        />
      </View>
    </Screen>
  );
}
