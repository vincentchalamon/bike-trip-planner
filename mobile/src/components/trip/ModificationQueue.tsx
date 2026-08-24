import { StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button } from '../ui';
import { Clock, Route } from '../ui/icons';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';

// Rough backend recompute seconds per modification, mirrored from the web
// ModificationQueue heuristic (observed p50 durations): a batch shows the rider
// why grouping edits saves round-trips.
const SECONDS_PER_MODIFICATION: Record<string, number> = {
  accommodation: 5,
  distance: 15,
  dates: 8,
  pacing: 10,
};

// Past this many estimated seconds we show "~1 min" rather than an exact count.
const MAX_DISPLAY_SECONDS = 59;

// Floating panel surfaced once one or more edits are queued (store
// pendingModifications). Mirrors pwa's modification-queue.tsx: the pending list,
// an estimated recompute time and two actions — "Apply and recompute" (one
// grouped POST /recompute via runApplyBatch) and "Cancel" (clears the queue).
// Auto-hides when the queue is empty. Disabled in the roadbook's read-only /
// degraded state so the batch write can't fire offline / API-down (#1166, #1179).
export function ModificationQueue({
  onApply,
  onCancel,
  applying = false,
  disabled = false,
  bottomOffset = 16,
}: {
  onApply: () => void;
  onCancel: () => void;
  applying?: boolean;
  disabled?: boolean;
  // Distance from the bottom edge; the caller lifts it above the system nav bar
  // (safe-area inset) so it never sits under the home indicator.
  bottomOffset?: number;
}) {
  const { t } = useTranslation();
  const theme = useTheme();
  const pending = useTripStore((s) => s.pendingModifications);

  if (pending.length === 0) return null;

  const totalSeconds = pending.reduce(
    (sum, m) => sum + (SECONDS_PER_MODIFICATION[m.type] ?? 5),
    0,
  );
  const estimate =
    totalSeconds > MAX_DISPLAY_SECONDS
      ? t('trip.modificationQueue.estimatedMinute')
      : t('trip.modificationQueue.estimatedSeconds', { seconds: totalSeconds });

  return (
    <View
      accessibilityLabel={t('trip.modificationQueue.panelA11y')}
      style={[
        styles.panel,
        {
          bottom: bottomOffset,
          backgroundColor: theme.colors.card,
          borderColor: theme.colors.border,
          borderRadius: theme.radius.xl,
          padding: theme.spacing.base,
          gap: theme.spacing.sm,
          ...theme.shadows.medium,
        },
      ]}
    >
      <View style={styles.header}>
        <Route color={theme.colors.brand} size={16} />
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sansSemibold,
            fontSize: 14,
          }}
        >
          {t('trip.modificationQueue.title', { count: pending.length })}
        </Text>
      </View>

      <View style={{ gap: theme.spacing.xs }}>
        {pending.map((m, i) => (
          <View key={`${m.type}-${m.stageIndex ?? 'trip'}-${i}`} style={styles.row}>
            <View style={[styles.dot, { backgroundColor: theme.colors.brand }]} />
            <Text
              style={{
                color: theme.colors.mutedForeground,
                fontFamily: theme.fonts.sans,
                fontSize: 13,
                flex: 1,
              }}
            >
              {m.label}
            </Text>
          </View>
        ))}
      </View>

      <View style={styles.estimate}>
        <Clock color={theme.colors.mutedIcon} size={13} />
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.mono,
            fontSize: 12,
          }}
        >
          {estimate}
        </Text>
      </View>

      <View style={styles.actions}>
        <Button
          label={t('trip.modificationQueue.cancel')}
          variant="ghost"
          size="sm"
          disabled={applying}
          onPress={onCancel}
        />
        <Button
          label={
            applying
              ? t('trip.modificationQueue.applying')
              : t('trip.modificationQueue.applyAll')
          }
          size="sm"
          loading={applying}
          disabled={disabled || applying}
          onPress={onApply}
        />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  panel: {
    position: 'absolute',
    left: 16,
    right: 16,
    borderWidth: StyleSheet.hairlineWidth,
  },
  header: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  row: { flexDirection: 'row', alignItems: 'flex-start', gap: 6 },
  dot: { width: 6, height: 6, borderRadius: 3, marginTop: 6 },
  estimate: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  actions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 8 },
});
