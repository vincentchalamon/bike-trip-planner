import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { useTheme } from '../../theme';

interface SseStatusIndicatorProps {
  // True while an SSE recompute is streaming (driven by the store's `computing`
  // flag, itself fed by the Mercure computation_step events in use-trip-live).
  computing: boolean;
}

// Small "calcul en cours" badge shown while the backend streams a recompute over
// SSE. Renders nothing when idle so callers can mount it unconditionally.
export function SseStatusIndicator({ computing }: SseStatusIndicatorProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  if (!computing) {
    return null;
  }
  return (
    <View
      accessibilityRole="progressbar"
      style={[
        styles.badge,
        {
          backgroundColor: theme.colors.accentSoft,
          borderRadius: theme.radius.full,
          paddingVertical: theme.spacing.xs,
          paddingHorizontal: theme.spacing.md,
        },
      ]}
    >
      <ActivityIndicator size="small" color={theme.colors.accentBrand} />
      <Text
        style={{
          color: theme.colors.accentInk,
          fontFamily: theme.fonts.sansMedium,
          fontSize: 13,
          marginLeft: theme.spacing.sm,
        }}
      >
        {t('trip.sse.computing')}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: { flexDirection: 'row', alignItems: 'center', alignSelf: 'flex-start' },
});
