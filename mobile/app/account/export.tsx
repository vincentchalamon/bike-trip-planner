import { useCallback } from 'react';
import { Alert, Text, View } from 'react-native';
import { Stack } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { Button, Screen } from '../../src/components/ui';
import { useAccountExport } from '../../src/hooks/use-account-export';
import { useTheme } from '../../src/theme';

export default function AccountExport() {
  const { t } = useTranslation();
  const theme = useTheme();

  const onFailure = useCallback(() => {
    Alert.alert(t('account.exportTitle'), t('account.export.failed'));
  }, [t]);

  const { exporting, exportAccount } = useAccountExport(onFailure);

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
          onPress={() => void exportAccount()}
        />
      </View>
    </Screen>
  );
}
