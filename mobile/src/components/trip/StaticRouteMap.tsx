import { useMemo } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import Svg, { Circle, Polyline } from 'react-native-svg';
import { useTranslation } from 'react-i18next';
import { CloudOff } from '../ui/icons';
import { useTheme } from '../../theme';
import { computeBounds, type MapMarker, type StageLine } from '../map/map-utils';

// Offline stand-in for the interactive MapLibre view (#1168): base-map tiles need
// the network, so rather than a blank tile-less canvas (or paying the native
// MapView init offline), draw a simple static thumbnail of the route — the same
// stage-colored polylines and markers — fitted into an SVG on a neutral surface,
// with a discreet "map unavailable offline" note. The elevation profile below
// stays live (it's local).

const VIEWBOX = 1000;
const PADDING = 48;
const INNER = VIEWBOX - PADDING * 2;

const MARKER_RADIUS = 7;

interface StaticRouteMapProps {
  stageSegments: StageLine[];
  markers: MapMarker[];
}

export function StaticRouteMap({ stageSegments, markers }: StaticRouteMapProps) {
  const theme = useTheme();
  const { t } = useTranslation();

  // Aspect-preserving projection of every [lon, lat] into the SVG box (y flipped,
  // since latitude grows upward), so the trace keeps its real shape rather than
  // stretching to fill.
  const projected = useMemo(() => {
    const all = stageSegments.flatMap((s) => s.coordinates);
    const bounds = computeBounds(all);
    if (!bounds) return null;
    const [west, south, east, north] = bounds;
    const spanX = east - west || 1e-9;
    const spanY = north - south || 1e-9;
    const scale = Math.min(INNER / spanX, INNER / spanY);
    const offsetX = (VIEWBOX - spanX * scale) / 2;
    const offsetY = (VIEWBOX - spanY * scale) / 2;
    const project = ([lon, lat]: [number, number]): [number, number] => [
      offsetX + (lon - west) * scale,
      offsetY + (north - lat) * scale,
    ];
    return {
      lines: stageSegments
        .filter((s) => s.coordinates.length > 1)
        .map((s) => ({
          color: s.color,
          points: s.coordinates.map(project).map(([x, y]) => `${x},${y}`).join(' '),
        })),
      dots: markers.map((m) => {
        const [x, y] = project([m.lon, m.lat]);
        return { x, y, kind: m.kind };
      }),
    };
  }, [stageSegments, markers]);

  const markerColor = (kind: MapMarker['kind']): string =>
    kind === 'accommodation'
      ? theme.colors.accentBrand
      : kind === 'poi'
        ? theme.colors.successInk
        : theme.colors.foreground;

  return (
    <View
      accessibilityLabel={t('trip.map.offlineStatic')}
      style={[styles.container, { backgroundColor: theme.colors.muted }]}
    >
      {projected ? (
        <Svg
          width="100%"
          height="100%"
          viewBox={`0 0 ${VIEWBOX} ${VIEWBOX}`}
          preserveAspectRatio="xMidYMid meet"
        >
          {projected.lines.map((l, i) => (
            <Polyline
              key={i}
              points={l.points}
              fill="none"
              stroke={l.color}
              strokeWidth={6}
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          ))}
          {projected.dots.map((d, i) => (
            <Circle
              key={i}
              cx={d.x}
              cy={d.y}
              r={MARKER_RADIUS}
              fill={markerColor(d.kind)}
              stroke={theme.colors.card}
              strokeWidth={2}
            />
          ))}
        </Svg>
      ) : null}
      <View
        pointerEvents="none"
        style={[
          styles.badge,
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
          {t('trip.map.offlineStatic')}
        </Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, overflow: 'hidden' },
  badge: {
    position: 'absolute',
    bottom: 12,
    left: 12,
    flexDirection: 'row',
    alignItems: 'center',
    borderRadius: 999,
    borderWidth: StyleSheet.hairlineWidth,
  },
});
