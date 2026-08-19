import { useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { profileHighlightSegment } from '@btp/core/elevation';
import { EmptyState } from '../ui';
import { Bike } from '../ui/icons';
import { TripMap } from '../TripMap';
import { collectMarkers } from '../map/map-utils';
import { ElevationProfile } from './ElevationProfile';
import { computeProfileSummary, groupThousands } from './trip-map-summary';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';

// The map tab: derives the route polyline and markers from the store's stages
// and hands them to the shared TripMap, or shows the empty state when there is
// no geometry yet. #1040 adds base-map toggle, markers and fit-bounds; #1041
// stacks the elevation profile below and shares the hover state so a touch on the
// profile surlines the matching stretch on the map. The Spike-UX restyle frames
// the map full-bleed with a fixed bottom profile panel and a floating ride CTA.
export function TripMapView() {
  const theme = useTheme();
  const { t } = useTranslation();
  const stages = useTripStore((s) => s.stages);
  const [hover, setHover] = useState<{ coordIndex: number; stageIndex: number } | null>(
    null,
  );

  const coordinates = useMemo<[number, number][]>(() => {
    const coords: [number, number][] = [];
    for (const stage of stages) {
      for (const point of stage.geometry ?? []) {
        coords.push([point.lon, point.lat]);
      }
    }
    return coords;
  }, [stages]);

  const markers = useMemo(() => collectMarkers(stages), [stages]);

  const summary = useMemo(() => computeProfileSummary(stages), [stages]);

  const highlightedSegment = useMemo(
    () =>
      hover
        ? profileHighlightSegment(stages, hover.stageIndex, hover.coordIndex)
        : undefined,
    [hover, stages],
  );

  // Placeholder — the "En selle" ride flow is not wired yet (Spike-UX FAB).
  const onRide = () => {};

  if (coordinates.length === 0) {
    return <EmptyState title={t('trip.mapEmpty')} />;
  }
  return (
    <View style={styles.container}>
      <View style={styles.map}>
        <TripMap
          coordinates={coordinates}
          markers={markers}
          highlightedSegment={highlightedSegment}
        />
        {/* "En selle" FAB: the in-ride screen is out of scope (Sprint 58), so this
            is a deliberate placeholder — disabled and dispatches nothing, like the
            roadbook FAB. */}
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('trip.map.rideCta')}
          accessibilityState={{ disabled: true }}
          disabled
          onPress={onRide}
          style={[styles.fab, { backgroundColor: theme.colors.brand }, theme.shadows.medium]}
        >
          <Bike size={18} color={theme.colors.primaryForeground} />
          <Text
            style={{
              color: theme.colors.primaryForeground,
              fontFamily: theme.fonts.sansSemibold,
              fontSize: 14,
            }}
          >
            {t('trip.map.rideCta')}
          </Text>
        </Pressable>
      </View>
      <View
        style={[
          styles.profile,
          {
            backgroundColor: theme.colors.card,
            borderTopColor: theme.colors.border,
            paddingHorizontal: theme.spacing.base,
            paddingTop: theme.spacing.md,
            paddingBottom: theme.spacing.base,
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
  fab: {
    position: 'absolute',
    right: 12,
    bottom: 12,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: 999,
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
