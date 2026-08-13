import { type ReactNode } from 'react';
import { StyleSheet, View, type ViewStyle } from 'react-native';
import { useTheme } from '../../theme/context';

interface CardProps {
  children: ReactNode;
  elevated?: boolean;
  style?: ViewStyle;
}

// Surface container with border + optional soft shadow.
export function Card({ children, elevated = false, style }: CardProps) {
  const theme = useTheme();
  return (
    <View
      style={[
        styles.base,
        {
          backgroundColor: theme.colors.card,
          borderColor: theme.colors.border,
          borderRadius: theme.radius.xl,
          padding: theme.spacing.base,
        },
        elevated && theme.shadows.soft,
        style,
      ]}
    >
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  base: { borderWidth: StyleSheet.hairlineWidth },
});
