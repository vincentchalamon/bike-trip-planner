import { type ReactNode } from 'react';
import { ScrollView, StyleSheet, View, type ViewStyle } from 'react-native';
import { SafeAreaView, type Edge } from 'react-native-safe-area-context';
import { useTheme } from '../../theme/context';

interface ScreenProps {
  children: ReactNode;
  scroll?: boolean;
  padded?: boolean;
  edges?: readonly Edge[];
  style?: ViewStyle;
}

// Root container for a screen: paints the surface background, applies safe-area
// insets and optional scrolling + standard horizontal padding.
export function Screen({
  children,
  scroll = false,
  padded = true,
  edges = ['top', 'left', 'right', 'bottom'],
  style,
}: ScreenProps) {
  const theme = useTheme();
  // A slight breathing gap between the last (often a button) row and the bottom
  // nav/gesture bar, on top of the safe-area inset (#1217 review).
  const bottomGap = edges.includes('bottom') ? theme.spacing.sm : 0;
  const inner: ViewStyle = {
    ...(padded ? { padding: theme.spacing.base } : {}),
    ...(bottomGap ? { paddingBottom: (padded ? theme.spacing.base : 0) + bottomGap } : {}),
  };

  return (
    <SafeAreaView style={[styles.fill, { backgroundColor: theme.colors.background }]} edges={edges}>
      {scroll ? (
        <ScrollView contentContainerStyle={[inner, style]} keyboardShouldPersistTaps="handled">
          {children}
        </ScrollView>
      ) : (
        <View style={[styles.fill, inner, style]}>{children}</View>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({ fill: { flex: 1 } });
