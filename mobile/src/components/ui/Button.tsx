import { type ReactNode } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
  type ViewStyle,
} from 'react-native';
import { useTheme } from '../../theme/context';
import type { Theme } from '../../theme';

export type ButtonVariant =
  | 'primary'
  | 'secondary'
  | 'outline'
  | 'outlineForest'
  | 'ghost'
  | 'destructive';
export type ButtonSize = 'sm' | 'md' | 'lg';

interface ButtonProps {
  label: string;
  onPress?: () => void;
  variant?: ButtonVariant;
  size?: ButtonSize;
  disabled?: boolean;
  loading?: boolean;
  icon?: ReactNode;
  fullWidth?: boolean;
  style?: ViewStyle;
}

function colorsFor(theme: Theme, variant: ButtonVariant) {
  const c = theme.colors;
  switch (variant) {
    case 'secondary':
      return { bg: c.secondary, fg: c.secondaryForeground, border: c.border };
    case 'outline':
      return { bg: 'transparent', fg: c.brand, border: c.brand };
    // Green-outlined secondary action (03-create-trip GPX button, #1214 palette B).
    case 'outlineForest':
      return { bg: 'transparent', fg: c.forest, border: c.forest };
    case 'ghost':
      return { bg: 'transparent', fg: c.foreground, border: 'transparent' };
    case 'destructive':
      return { bg: c.destructive, fg: c.destructiveForeground, border: c.destructive };
    default:
      return { bg: c.brandFill, fg: '#ffffff', border: c.brandFill };
  }
}

const heights: Record<ButtonSize, number> = { sm: 36, md: 44, lg: 52 };

export function Button({
  label,
  onPress,
  variant = 'primary',
  size = 'md',
  disabled = false,
  loading = false,
  icon,
  fullWidth = false,
  style,
}: ButtonProps) {
  const theme = useTheme();
  const { bg, fg, border } = colorsFor(theme, variant);
  const isDisabled = disabled || loading;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      disabled={isDisabled}
      onPress={onPress}
      style={({ pressed }) => [
        styles.base,
        {
          height: heights[size],
          paddingHorizontal: theme.spacing.base,
          borderRadius: theme.radius.lg,
          backgroundColor: bg,
          borderColor: border,
          opacity: isDisabled ? 0.5 : pressed ? 0.85 : 1,
        },
        fullWidth && styles.fullWidth,
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={fg} />
      ) : (
        <View style={styles.content}>
          {icon ? <View style={styles.icon}>{icon}</View> : null}
          <Text style={{ color: fg, fontFamily: theme.fonts.sansSemibold, fontSize: 16 }}>
            {label}
          </Text>
        </View>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: {
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: StyleSheet.hairlineWidth,
  },
  fullWidth: { alignSelf: 'stretch' },
  content: { flexDirection: 'row', alignItems: 'center' },
  icon: { marginRight: 8 },
});
