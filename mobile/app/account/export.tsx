import { useCallback, useState } from 'react';
import { Alert, StyleSheet, Text, View } from 'react-native';
import { Stack } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Button, Screen } from '../../src/components/ui';
import { useAccountExport } from '../../src/hooks/use-account-export';
import { useTheme } from '../../src/theme';

export default function AccountExport() {
  const { t } = useTranslation();
  const theme = useTheme();
  const [exported, setExported] = useState(false);

  const onFailure = useCallback(() => {
    Alert.alert(t('account.exportTitle'), t('account.export.failed'));
  }, [t]);

  const { exporting, exportAccount } = useAccountExport(onFailure);

  const onExport = useCallback(async () => {
    setExported(false);
    const ok = await exportAccount();
    if (ok) setExported(true);
  }, [exportAccount]);

  return (
    <Screen>
      <Stack.Screen options={{ headerShown: true, title: t('account.exportTitle') }} />
      <View style={{ flex: 1, gap: theme.spacing.lg }}>
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 15,
            lineHeight: 22,
          }}
        >
          {t('account.export.description')}
        </Text>
        <Button
          label={t('account.export.action')}
          loading={exporting}
          onPress={() => void onExport()}
        />
        {exported ? (
          <View
            accessibilityRole="alert"
            style={{
              backgroundColor: theme.colors.successSoft,
              borderColor: theme.colors.successBorder,
              borderWidth: StyleSheet.hairlineWidth,
              borderRadius: theme.radius.md,
              paddingVertical: theme.spacing.md,
              paddingHorizontal: theme.spacing.base,
            }}
          >
            <Text
              style={{ color: theme.colors.successInk, fontFamily: theme.fonts.sansMedium, fontSize: 14 }}
            >
              {t('account.export.success')}
            </Text>
          </View>
        ) : null}
      </View>
    </Screen>
  );
}
