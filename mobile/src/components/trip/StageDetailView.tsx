import { type ReactNode, useEffect, useMemo, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button, EmptyState, LoadingState } from '../ui';
import { ArrowLeft, ChevronRight, Mountain, Route } from '../ui/icons';
import { TripMap } from '../TripMap';
import { collectMarkers } from '../map/map-utils';
import { ElevationProfile } from './ElevationProfile';
import { StageDataBlocks } from './StageDataBlocks';
import { formatStageDate, stageDateFor } from './roadbook-dates';
import {
  activeStageIndex,
  clampIndex,
  hasNextStage,
  hasPrevStage,
  stageGeometryCoords,
  stageStats,
  surfaceShares,
} from './stage-detail';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';

// Full-screen detail for one stage (#1039): a bounded prev/next header, the
// stage stats (distance / D+ / D-), its own map (fit to this stage), the
// elevation profile focused on the stage, and the shared per-day data blocks
// (weather / alerts / POI / accommodation / supply / events). Reads straight
// from the live store; the stage index is local state so prev/next stays on one
// mounted screen (no navigation stacking, no SSE re-subscribe).
export function StageDetailView({
  id,
  initialIndex,
}: {
  id: string;
  initialIndex: number;
}) {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  const stages = useTripStore((s) => s.stages);
  const startDate = useTripStore((s) => s.startDate);
  const loading = useTripStore((s) => s.loading);
  const [index, setIndex] = useState(initialIndex);

  // Keep the index valid as stages hydrate / change under us.
  const count = stages.length;
  const safeIndex = clampIndex(index, count);
  useEffect(() => {
    if (safeIndex !== index) setIndex(safeIndex);
  }, [safeIndex, index]);

  const stage = stages[safeIndex];

  const coordinates = useMemo(
    () => (stage ? stageGeometryCoords(stage) : []),
    [stage],
  );
  const markers = useMemo(() => (stage ? collectMarkers([stage]) : []), [stage]);
  const profileIndex = useMemo(
    () => activeStageIndex(stages, safeIndex),
    [stages, safeIndex],
  );

  if (!stage) {
    return loading ? (
      <LoadingState />
    ) : (
      <EmptyState title={t('trip.stageDetail.notFound')} />
    );
  }

  const date = stageDateFor(startDate, stage.dayNumber ?? safeIndex + 1);
  const heading = date
    ? formatStageDate(date, i18n.language)
    : t('trip.day', { day: stage.dayNumber ?? safeIndex + 1 });
  const stats = stageStats(stage);
  const surfaces = surfaceShares(stage);

  return (
    <ScrollView
      style={{ backgroundColor: theme.colors.background }}
      contentContainerStyle={{ paddingBottom: theme.spacing.xl }}
    >
      <View style={{ padding: theme.spacing.base, gap: theme.spacing.md }}>
        <View style={styles.navRow}>
          <Button
            variant="secondary"
            size="sm"
            label={t('trip.stageDetail.prev')}
            icon={<ArrowLeft color={theme.colors.secondaryForeground} size={16} />}
            disabled={!hasPrevStage(safeIndex)}
            onPress={() => setIndex(safeIndex - 1)}
          />
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sansMedium,
              fontSize: 13,
            }}
          >
            {t('trip.stageDetail.position', {
              current: safeIndex + 1,
              total: count,
            })}
          </Text>
          <Button
            variant="secondary"
            size="sm"
            label={t('trip.stageDetail.next')}
            icon={<ChevronRight color={theme.colors.secondaryForeground} size={16} />}
            disabled={!hasNextStage(safeIndex, count)}
            onPress={() => setIndex(safeIndex + 1)}
          />
        </View>

        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.serif,
            fontSize: 22,
          }}
        >
          {heading}
          {stage.isRestDay ? ` · ${t('trip.rest')}` : ''}
        </Text>
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 15,
          }}
        >
          {stage.startLabel ?? '?'} → {stage.endLabel ?? stage.label ?? '?'}
        </Text>

        <View style={styles.statsRow}>
          <StatCell
            icon={<Route color={theme.colors.mutedIcon} size={16} />}
            label={t('trip.stageDetail.distance')}
            value={t('trip.stageDetail.distanceValue', { value: stats.distanceKm })}
          />
          <StatCell
            icon={<Mountain color={theme.colors.mutedIcon} size={16} />}
            label={t('trip.stageDetail.ascent')}
            value={t('trip.stageDetail.elevationValue', { value: stats.elevationGain })}
          />
          <StatCell
            icon={<Mountain color={theme.colors.mutedIcon} size={16} />}
            label={t('trip.stageDetail.descent')}
            value={t('trip.stageDetail.elevationValue', { value: stats.elevationLoss })}
          />
        </View>

        {surfaces.length > 0 ? (
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.mono,
              fontSize: 13,
            }}
          >
            {t('trip.stageDetail.surface')}:{' '}
            {surfaces.map((s) => `${s.surface} ${s.percent}%`).join(' · ')}
          </Text>
        ) : null}
      </View>

      <View style={{ height: 240 }}>
        {coordinates.length > 0 ? (
          <TripMap coordinates={coordinates} markers={markers} />
        ) : (
          <View style={{ flex: 1 }}>
            <EmptyState title={t('trip.mapEmpty')} />
          </View>
        )}
      </View>

      <View style={{ padding: theme.spacing.base }}>
        <ElevationProfile
          stages={stages}
          focusedStageIndex={profileIndex}
          onHover={() => {}}
        />
      </View>

      <View
        style={{
          paddingHorizontal: theme.spacing.base,
          paddingBottom: theme.spacing.base,
        }}
      >
        <StageDataBlocks stage={stage} />
      </View>
    </ScrollView>
  );
}

function StatCell({
  icon,
  label,
  value,
}: {
  icon: ReactNode;
  label: string;
  value: string;
}) {
  const theme = useTheme();
  return (
    <View
      style={[
        styles.statCell,
        { backgroundColor: theme.colors.card, borderColor: theme.colors.border },
      ]}
    >
      <View style={styles.statHead}>
        {icon}
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 12,
          }}
        >
          {label}
        </Text>
      </View>
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.mono,
          fontSize: 15,
        }}
      >
        {value}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  navRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
  },
  statsRow: { flexDirection: 'row', gap: 8 },
  statCell: {
    flex: 1,
    gap: 4,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 10,
  },
  statHead: { flexDirection: 'row', alignItems: 'center', gap: 6 },
});
