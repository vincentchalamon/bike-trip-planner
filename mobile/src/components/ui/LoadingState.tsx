import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { useTheme } from '../../theme/context';

// Centered spinner with an optional caption, for pending screens/sections.
export function LoadingState({ label }: { label?: string }) {
  const theme = useTheme();
  return (
    <View style={styles.container}>
      <ActivityIndicator color={theme.colors.brand} />
      {label ? (
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 14,
            marginTop: theme.spacing.md,
          }}
        >
          {label}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
});
