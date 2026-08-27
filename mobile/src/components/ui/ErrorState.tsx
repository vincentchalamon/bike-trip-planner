import { type ReactNode } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { useTheme } from '../../theme/context';
import { Button } from './Button';

interface ErrorStateProps {
  title?: string;
  description?: string;
  icon?: ReactNode;
  onRetry?: () => void;
  retryLabel?: string;
}

// Error placeholder with a destructive-toned title and an optional retry CTA.
export function ErrorState({
  title,
  description,
  icon,
  onRetry,
  retryLabel,
}: ErrorStateProps) {
  const theme = useTheme();
  const { t } = useTranslation();
  const resolvedTitle = title ?? t('common.error');
  const resolvedRetryLabel = retryLabel ?? t('common.retry');
  return (
    <View style={styles.container}>
      {icon ? <View style={{ marginBottom: theme.spacing.base }}>{icon}</View> : null}
      <Text
        style={{
          color: theme.colors.destructive,
          fontFamily: theme.fonts.serif,
          fontSize: 20,
          textAlign: 'center',
        }}
      >
        {resolvedTitle}
      </Text>
      {description ? (
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 15,
            textAlign: 'center',
            marginTop: theme.spacing.sm,
          }}
        >
          {description}
        </Text>
      ) : null}
      {onRetry ? (
        <View style={{ marginTop: theme.spacing.lg }}>
          <Button label={resolvedRetryLabel} variant="secondary" onPress={onRetry} />
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 32 },
});
