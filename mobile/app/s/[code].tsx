import { Stack, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useState } from 'react';
import { Alert, PanResponder, Pressable, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import {
  ErrorState,
  LoadingState,
  Screen,
  SegmentedControl,
  type Segment,
} from '../../src/components/ui';
import { Download, Eye } from '../../src/components/ui/icons';
import { RoadbookView, TripMapView } from '../../src/components/trip';
import { useTheme } from '../../src/theme';
import { useSharedTrip } from '../../src/hooks/use-shared-trip';
import { confirmExportFormat, writeAndShare } from '../../src/hooks/use-export';
import {
  fetchSharedTripExport,
  tripExportFileName,
  type ExportFormat,
} from '../../src/api/trips';
import { useTripStore } from '../../src/store/trip-store';
import { swipeToView } from '../../src/lib/swipe';

type TripView = 'roadbook' | 'map';

// Anonymous read-only consultation of a shared trip (#1177), reached via the
// `/s/<code>` App Link (see app.json intentFilters) or the custom scheme. Mirrors
// the web shared page (pwa's shared-trip-page): roadbook + map + downloads, a
// permanent "shared view / read only" banner, and no edit affordances at all.
export default function SharedTrip() {
  const { code } = useLocalSearchParams<{ code: string }>();
  const { t } = useTranslation();
  const theme = useTheme();
  const [view, setView] = useState<TripView>('roadbook');
  const [hasViewedMap, setHasViewedMap] = useState(false);
  useEffect(() => {
    if (view === 'map') setHasViewedMap(true);
  }, [view]);

  useSharedTrip(code);

  const title = useTripStore((s) => s.title);
  const loading = useTripStore((s) => s.loading);
  const error = useTripStore((s) => s.error);
  const resolvedTitle = title ?? t('sharePage.title');

  const panResponder = useMemo(
    () =>
      PanResponder.create({
        onMoveShouldSetPanResponder: (_, g) =>
          Math.abs(g.dx) > 24 && Math.abs(g.dx) > Math.abs(g.dy) * 1.5,
        onPanResponderRelease: (_, g) => {
          const next = swipeToView(g.dx);
          if (next) setView(next);
        },
      }),
    [],
  );

  async function download(format: ExportFormat) {
    try {
      const bytes = await fetchSharedTripExport(code, format);
      await writeAndShare(bytes, tripExportFileName(resolvedTitle, format), format);
    } catch {
      Alert.alert(t('export.failedTitle'), t('export.failedMessage'));
    }
  }

  const segments: readonly Segment<TripView>[] = [
    { value: 'roadbook', label: t('trip.segmentRoadbook') },
    { value: 'map', label: t('trip.segmentMap') },
  ];

  if (loading) {
    return (
      <Screen padded={false} edges={['top', 'left', 'right']}>
        <Stack.Screen options={{ headerShown: false }} />
        <LoadingState />
      </Screen>
    );
  }

  // Any load error on the anonymous view means the link is invalid or revoked —
  // there is nothing the visitor can do but head back, so a single copy covers it.
  if (error) {
    return (
      <Screen padded={false} edges={['top', 'left', 'right']}>
        <Stack.Screen options={{ title: t('sharePage.title') }} />
        <ErrorState
          title={t('sharePage.title')}
          description={t('sharePage.error')}
        />
      </Screen>
    );
  }

  return (
    <Screen padded={false} edges={['top', 'left', 'right']}>
      <Stack.Screen
        options={{
          headerShown: true,
          title: resolvedTitle,
          headerRight: () => (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('sharePage.download')}
              onPress={() =>
                confirmExportFormat({
                  title: t('sharePage.download'),
                  gpxLabel: t('export.gpx'),
                  fitLabel: t('export.fit'),
                  cancelLabel: t('export.cancel'),
                  onSelect: (format) => void download(format),
                })
              }
              hitSlop={8}
              style={{ padding: theme.spacing.xs }}
            >
              <Download color={theme.colors.foreground} size={22} />
            </Pressable>
          ),
        }}
      />

      {/* Permanent read-only banner — mirrors the web SharedViewBanner. */}
      <View
        accessibilityRole="header"
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          gap: theme.spacing.sm,
          marginHorizontal: theme.spacing.base,
          marginTop: theme.spacing.sm,
          padding: theme.spacing.md,
          borderRadius: theme.radius.lg,
          backgroundColor: theme.colors.accentSoft,
        }}
      >
        <Eye color={theme.colors.accentBrand} size={18} />
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 14,
            flex: 1,
          }}
        >
          {t('sharePage.readOnlyBanner')}
        </Text>
      </View>

      <View
        style={{
          paddingHorizontal: theme.spacing.base,
          paddingTop: theme.spacing.sm,
          paddingBottom: theme.spacing.sm,
        }}
      >
        <SegmentedControl segments={segments} value={view} onChange={setView} />
      </View>

      <View style={{ flex: 1 }} {...panResponder.panHandlers}>
        {/* Keep both tabs mounted once opened (perf, mirrors trip/[id]); the map
            only mounts after its first open. Geometry is applied from the public
            /route by useSharedTrip, so TripMapView's own /route fetch stays off
            (its useTripRoute is gated on the store's tripId, left null here). */}
        <View style={{ flex: 1, display: view === 'map' ? 'flex' : 'none' }}>
          {hasViewedMap ? <TripMapView /> : null}
        </View>
        <View style={{ flex: 1, display: view === 'roadbook' ? 'flex' : 'none' }}>
          <RoadbookView id={code} readOnly />
        </View>
      </View>
    </Screen>
  );
}
