import { StyleSheet, Text, TextInput, View, type TextInputProps } from 'react-native';
import { useTheme } from '../../theme/context';

interface InputProps extends Omit<TextInputProps, 'style'> {
  label?: string;
  error?: string;
}

// Labelled text field with an error line; border turns destructive on error.
export function Input({ label, error, ...props }: InputProps) {
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
      <TextInput
        placeholderTextColor={theme.colors.mutedForeground}
        style={[
          styles.field,
          {
            color: theme.colors.foreground,
            backgroundColor: theme.colors.surface,
            borderColor: error ? theme.colors.destructive : theme.colors.input,
            borderRadius: theme.radius.md,
            paddingHorizontal: theme.spacing.md,
            fontFamily: theme.fonts.sans,
          },
        ]}
        {...props}
      />
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
  field: { height: 44, borderWidth: StyleSheet.hairlineWidth, fontSize: 16 },
});
