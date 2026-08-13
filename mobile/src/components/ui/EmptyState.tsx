import { type ReactNode } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useTheme } from '../../theme/context';
import { Button } from './Button';

interface EmptyStateProps {
  title: string;
  description?: string;
  icon?: ReactNode;
  actionLabel?: string;
  onAction?: () => void;
}

// Neutral placeholder for empty lists/screens, with an optional CTA.
export function EmptyState({ title, description, icon, actionLabel, onAction }: EmptyStateProps) {
  const theme = useTheme();
  return (
    <View style={styles.container}>
      {icon ? <View style={{ marginBottom: theme.spacing.base }}>{icon}</View> : null}
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.serif,
          fontSize: 20,
          textAlign: 'center',
        }}
      >
        {title}
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
      {actionLabel && onAction ? (
        <View style={{ marginTop: theme.spacing.lg }}>
          <Button label={actionLabel} onPress={onAction} />
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 32 },
});
