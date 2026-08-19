import { type ReactNode } from 'react';
import { StyleSheet, Text, TextInput, View, type TextInputProps } from 'react-native';
import { useTheme } from '../../theme/context';

interface InputProps extends Omit<TextInputProps, 'style'> {
  label?: string;
  error?: string;
  leadingIcon?: ReactNode;
}

// Labelled text field with an error line; border turns destructive on error.
// An optional leading icon sits inside the bordered field, ahead of the input.
export function Input({ label, error, leadingIcon, ...props }: InputProps) {
  const theme = useTheme();
  return (
    <View>
      {label ? (
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 14,
            marginBottom: theme.spacing.xs,
          }}
        >
          {label}
        </Text>
      ) : null}
      <View
        style={[
          styles.field,
          {
            backgroundColor: theme.colors.surface,
            borderColor: error ? theme.colors.destructive : theme.colors.input,
            borderRadius: theme.radius.md,
            paddingHorizontal: theme.spacing.md,
          },
        ]}
      >
        {leadingIcon ? <View style={{ marginRight: theme.spacing.sm }}>{leadingIcon}</View> : null}
        <TextInput
          placeholderTextColor={theme.colors.mutedForeground}
          style={[styles.input, { color: theme.colors.foreground, fontFamily: theme.fonts.sans }]}
          {...props}
        />
      </View>
      {error ? (
        <Text
          style={{
            color: theme.colors.destructive,
            fontFamily: theme.fonts.sans,
            fontSize: 13,
            marginTop: theme.spacing.xs,
          }}
        >
          {error}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  field: {
    height: 44,
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: StyleSheet.hairlineWidth,
  },
  input: { flex: 1, height: '100%', fontSize: 16, padding: 0 },
});
