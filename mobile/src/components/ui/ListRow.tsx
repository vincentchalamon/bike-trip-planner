import { type ReactNode } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useTheme } from '../../theme/context';

interface ListRowProps {
  title: string;
  subtitle?: string;
  left?: ReactNode;
  right?: ReactNode;
  danger?: boolean;
  onPress?: () => void;
}

// Single tappable row: optional leading slot, title + subtitle, trailing slot.
export function ListRow({ title, subtitle, left, right, danger, onPress }: ListRowProps) {
  const theme = useTheme();
  return (
    <Pressable
      accessibilityRole={onPress ? 'button' : undefined}
      disabled={!onPress}
      onPress={onPress}
      style={({ pressed }) => [
        styles.row,
        {
          paddingVertical: theme.spacing.md,
          paddingHorizontal: theme.spacing.base,
          borderBottomColor: theme.colors.border,
          backgroundColor: pressed && onPress ? theme.colors.muted : 'transparent',
        },
      ]}
    >
      {left ? <View style={{ marginRight: theme.spacing.md }}>{left}</View> : null}
      <View style={styles.body}>
        <Text
          numberOfLines={1}
          style={{
            color: danger ? theme.colors.destructive : theme.colors.foreground,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 16,
          }}
        >
          {title}
        </Text>
        {subtitle ? (
          <Text
            numberOfLines={1}
            style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sans, fontSize: 13, marginTop: 2 }}
          >
            {subtitle}
          </Text>
        ) : null}
      </View>
      {right ? <View style={{ marginLeft: theme.spacing.md }}>{right}</View> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', borderBottomWidth: StyleSheet.hairlineWidth },
  body: { flex: 1 },
});
