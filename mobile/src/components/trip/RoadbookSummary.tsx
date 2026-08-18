import { StyleSheet, Text, View } from 'react-native';
import Svg, { Defs, LinearGradient, Rect, Stop } from 'react-native-svg';
import { useTranslation } from 'react-i18next';
import type { StageData } from '@btp/core';
import { computeEstimatedBudget, computeTripTotals } from '../../lib/share';
import { formatTripDateRange } from '../../lib/dates';
import { useTheme } from '../../theme';

interface RoadbookSummaryProps {
  stages: StageData[];
  startDate: string | null;
  endDate: string | null;
}

// The green summary card (Spike-UX): trip dates over a metrics grid, laid on a
// dark-green gradient. Pure read from the store's stages/dates — the same core
// budget/totals primitives the share card uses (ADR-055), so a SSE recompute
// updates it in place with no local state.
export function RoadbookSummary({
  stages,
  startDate,
  endDate,
}: RoadbookSummaryProps) {
  const { t, i18n } = useTranslation();
  const theme = useTheme();

  const totals = computeTripTotals(stages);
  const budget = computeEstimatedBudget(stages);
  const budgetAvg = Math.round((budget.min + budget.max) / 2);
  const weather = stages.find((s) => s.weather)?.weather ?? null;

  const metrics: { value: string; label: string }[] = [
    { value: t('trip.summary.km', { value: Math.round(totals.totalDistance) }), label: t('trip.summary.distance') },
    { value: t('trip.summary.meters', { value: `+${Math.round(totals.totalElevation)}` }), label: t('trip.summary.ascent') },
    { value: t('trip.summary.meters', { value: `-${Math.round(totals.totalElevationLoss)}` }), label: t('trip.summary.descent') },
    { value: String(stages.length), label: t('trip.summary.stages') },
  ];
  if (budgetAvg > 0) {
    metrics.push({ value: t('trip.summary.euro', { value: budgetAvg }), label: t('trip.summary.budget') });
  }
  if (weather) {
    metrics.push({
      value: t('trip.summary.degrees', { value: Math.round(weather.tempMax) }),
      label: t('trip.summary.weather'),
    });
  }

  return (
    <View style={[styles.card, { borderRadius: theme.radius.xl }]}>
      <Svg style={StyleSheet.absoluteFill} width="100%" height="100%">
        <Defs>
          <LinearGradient id="summaryGrad" x1="0" y1="0" x2="0.35" y2="1">
            <Stop offset="0" stopColor={theme.colors.summary} />
            <Stop offset="1" stopColor={theme.colors.summaryEnd} />
          </LinearGradient>
        </Defs>
        <Rect x="0" y="0" width="100%" height="100%" fill="url(#summaryGrad)" />
      </Svg>
      <View style={{ padding: theme.spacing.lg, gap: theme.spacing.md }}>
        <Text
          style={{
            color: theme.colors.summaryForeground,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 14,
          }}
        >
          {formatTripDateRange(startDate, endDate, i18n.language) ||
            t('trip.summary.noDates')}
        </Text>
        <View style={styles.grid}>
          {metrics.map((m) => (
            <View key={m.label} style={styles.metric}>
              <Text
                style={{
                  color: theme.colors.summaryForeground,
                  fontFamily: theme.fonts.serif,
                  fontSize: 20,
                }}
              >
                {m.value}
              </Text>
              <Text
                style={{
                  color: theme.colors.summaryMuted,
                  fontFamily: theme.fonts.sansMedium,
                  fontSize: 11,
                  letterSpacing: 0.6,
                  textTransform: 'uppercase',
                  marginTop: 2,
                }}
              >
                {m.label}
              </Text>
            </View>
          ))}
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { overflow: 'hidden' },
  grid: { flexDirection: 'row', flexWrap: 'wrap' },
  metric: { width: '33.3333%', paddingVertical: 6 },
});
