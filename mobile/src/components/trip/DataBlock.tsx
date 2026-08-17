import { type ReactNode } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { Card } from '../ui';
import { useTheme } from '../../theme';

interface DataBlockProps {
  title: string;
  // Whether the block has no data to show: renders the muted placeholder.
  isEmpty: boolean;
  emptyLabel: string;
  // Optional leading icon and trailing count badge for the header.
  icon?: ReactNode;
  count?: number;
  children?: ReactNode;
}

// Structural wrapper shared by every per-day data family (alerts, weather, POI,
// accommodations, supply, events): a titled card that renders its children or a
// muted empty placeholder. #1038 fills the family blocks with real content; this
// keeps their outer shell and empty state consistent.
export function DataBlock({ title, isEmpty, emptyLabel, icon, count, children }: DataBlockProps) {
  const theme = useTheme();
  return (
    <Card>
      <View style={styles.header}>
        {icon ? <View style={{ marginRight: theme.spacing.sm }}>{icon}</View> : null}
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sansSemibold,
            fontSize: 16,
            flex: 1,
          }}
        >
          {title}
        </Text>
        {typeof count === 'number' && count > 0 ? (
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.mono,
              fontSize: 13,
            }}
          >
            {count}
          </Text>
        ) : null}
      </View>
      {isEmpty ? (
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 14,
            marginTop: theme.spacing.sm,
          }}
        >
          {emptyLabel}
        </Text>
      ) : (
        <View style={{ marginTop: theme.spacing.sm, gap: theme.spacing.xs }}>{children}</View>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  header: { flexDirection: 'row', alignItems: 'center' },
});
