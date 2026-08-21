import { useMemo, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useTranslation } from 'react-i18next';
import { profileHighlightSegment } from '@btp/core/elevation';
import { EmptyState } from '../ui';
import { CloudOff } from '../ui/icons';
import { TripMap } from '../TripMap';
import { buildStageLines, collectMarkers } from '../map/map-utils';
import { ElevationProfile } from './ElevationProfile';
import { computeProfileSummary, groupThousands } from './trip-map-summary';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';
import { useOfflineStore } from '../../store/offline-store';
import { useTripRoute } from '../../hooks/use-trip-route';

// The map tab: derives the route polyline and markers from the store's stages
// and hands them to the shared TripMap, or shows the empty state when there is
// no geometry yet. #1040 adds base-map toggle, markers and fit-bounds; #1041
// stacks the elevation profile below and shares the hover state so a touch on the
// profile surlines the matching stretch on the map. The Spike-UX restyle frames
// the map full-bleed with a fixed bottom profile panel (in-ride CTA lives on the
// Roadbook tab, not here).
export function TripMapView() {
  // The summary omits geometry (ADR-057); pull the route in so the map has a line.
  useTripRoute();
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const { t } = useTranslation();
  const stages = useTripStore((s) => s.stages);
  const isOnline = useOfflineStore((s) => s.isOnline);
  const [hover, setHover] = useState<{ coordIndex: number; stageIndex: number } | null>(
    null,
  );

  // One colored polyline per stage so each stage is visually distinct on the map.
  const stageSegments = useMemo(() => buildStageLines(stages), [stages]);

  const markers = useMemo(() => collectMarkers(stages), [stages]);

  const summary = useMemo(() => computeProfileSummary(stages), [stages]);

  const highlightedSegment = useMemo(
    () =>
      hover
        ? profileHighlightSegment(stages, hover.stageIndex, hover.coordIndex)
        : undefined,
    [hover, stages],
  );

  if (stageSegments.length === 0) {
    return <EmptyState title={t('trip.mapEmpty')} />;
  }
  return (
    <View style={styles.container}>
      <View style={styles.map}>
        <TripMap
          stageSegments={stageSegments}
          markers={markers}
          highlightedSegment={highlightedSegment}
        />
        {/* Discrete "offline map" indicator: when connectivity drops, TripMap
            degrades to the tile-less style (route + profile stay local); this
            badge tells the rider why the base imagery is gone. */}
        {!isOnline ? (
          <View
            pointerEvents="none"
            accessibilityRole="text"
            style={[
              styles.offlineBadge,
              {
                backgroundColor: theme.colors.card,
                borderColor: theme.colors.border,
                paddingHorizontal: theme.spacing.sm,
                paddingVertical: theme.spacing.xs,
                gap: theme.spacing.xs,
                ...theme.shadows.soft,
              },
            ]}
          >
            <CloudOff size={14} color={theme.colors.mutedForeground} />
            <Text
              style={{
                color: theme.colors.mutedForeground,
                fontFamily: theme.fonts.sansMedium,
                fontSize: 12,
              }}
            >
              {t('trip.map.offline')}
            </Text>
          </View>
        ) : null}
      </View>
      {/* Fixed bottom profile panel: the map (flex:1) takes the remaining height
          and the panel keeps its intrinsic height (title + SVG + axis), so map +
          profile + axis always fit without a ScrollView. The safe-area bottom
          inset is added to the padding so the distance/elevation axis is never
          hidden under the system navigation bar. */}
      <View
        style={[
          styles.profile,
          {
            backgroundColor: theme.colors.card,
            borderTopColor: theme.colors.border,
            paddingHorizontal: theme.spacing.base,
            paddingTop: theme.spacing.md,
            paddingBottom: theme.spacing.base + insets.bottom,
            gap: theme.spacing.sm,
          },
        ]}
      >
        <View style={styles.profileHeader}>
          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sansSemibold,
              fontSize: 15,
            }}
          >
            {t('trip.map.profileTitle')}
          </Text>
          {summary ? (
            <Text
              style={{
                color: theme.colors.mutedForeground,
                fontFamily: theme.fonts.mono,
                fontSize: 13,
              }}
            >
              {t('trip.map.profileSummary', {
                distance: groupThousands(summary.distanceKm),
                gain: groupThousands(summary.gain),
              })}
            </Text>
          ) : null}
        </View>
        <ElevationProfile
          stages={stages}
          focusedStageIndex={null}
          onHover={(coordIndex, stageIndex) =>
            setHover(
              coordIndex === null || stageIndex === null
                ? null
                : { coordIndex, stageIndex },
            )
          }
        />
        {summary ? (
          <View style={styles.axis}>
            <Text style={[styles.axisText, { color: theme.colors.mutedForeground, fontFamily: theme.fonts.mono }]}>
              {t('trip.map.profileAxisPoint', {
                distance: 0,
                ele: groupThousands(summary.startEle),
              })}
            </Text>
            <Text style={[styles.axisText, { color: theme.colors.mutedForeground, fontFamily: theme.fonts.mono }]}>
              {t('trip.map.profileAxisMax', { ele: groupThousands(summary.maxEle) })}
            </Text>
            <Text style={[styles.axisText, { color: theme.colors.mutedForeground, fontFamily: theme.fonts.mono }]}>
              {t('trip.map.profileAxisPoint', {
                distance: groupThousands(summary.distanceKm),
                ele: groupThousands(summary.endEle),
              })}
            </Text>
          </View>
        ) : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  map: { flex: 1 },
  offlineBadge: {
    position: 'absolute',
    bottom: 12,
    left: 12,
    flexDirection: 'row',
    alignItems: 'center',
    borderRadius: 999,
    borderWidth: StyleSheet.hairlineWidth,
  },
  profile: { borderTopWidth: StyleSheet.hairlineWidth },
  profileHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  axis: { flexDirection: 'row', justifyContent: 'space-between' },
  axisText: { fontSize: 11 },
});
