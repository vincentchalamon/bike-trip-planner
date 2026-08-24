import { StyleSheet, Text, View } from 'react-native';
import { AlertTriangle } from '../ui/icons';
import { useTheme } from '../../theme';

export type RoadbookBannerVariant =
  | 'locked'
  | 'outOfZone'
  | 'noDates'
  | 'offline'
  | 'apiUnavailable';

interface RoadbookBannerProps {
  variant: RoadbookBannerVariant;
  message: string;
}

// A single informational banner above the roadbook: read-only lock (started
// trip, API 423), out-of-zone route, or missing dates. The variant only tunes
// the accent colour; the copy is passed in so the caller owns i18n.
export function RoadbookBanner({ variant, message }: RoadbookBannerProps) {
  const theme = useTheme();
  const accent =
    variant === 'noDates' ? theme.colors.accentBrand : theme.colors.destructive;
  return (
    <View
      accessibilityRole="alert"
      style={[
        styles.banner,
        {
          backgroundColor: theme.colors.accentSoft,
          borderColor: accent,
          borderLeftWidth: 3,
          borderLeftColor: accent,
          borderRadius: theme.radius.lg,
          padding: theme.spacing.md,
          gap: theme.spacing.sm,
        },
      ]}
    >
      <AlertTriangle color={accent} size={18} />
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.sansMedium,
          fontSize: 14,
          flex: 1,
        }}
      >
        {message}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  banner: {
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: StyleSheet.hairlineWidth,
  },
});
