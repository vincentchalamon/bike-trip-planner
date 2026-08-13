import { useMemo, useState } from 'react';
import {
  type GestureResponderEvent,
  type LayoutChangeEvent,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import Svg, { Line, Path } from 'react-native-svg';
import { useTranslation } from 'react-i18next';
import type { StageData } from '@btp/core';
import { buildProfilePoints, findClosestProfilePoint } from '@btp/core/elevation';
import { useTheme } from '../../theme';

// SVG viewport constants (mirrors the web profile). The Svg fills its container
// width via a non-uniform viewBox; touch X is projected back into this space.
const VW = 800;
const VH = 160;
const PAD_L = 4;
const PAD_R = 8;
const PAD_T = 8;
const PAD_B = 20;
const SVG_HEIGHT = 100;

interface ElevationProfileProps {
  stages: StageData[];
  focusedStageIndex: number | null;
  // Fires the hovered geometry point (active-stage index + coord index) as the
  // finger moves, or (null, null) on release, so the container can drive the map
  // highlight. Reciprocity is one-way (profile → map).
  onHover: (coordIndex: number | null, stageIndex: number | null) => void;
}

// Touch-driven elevation profile in react-native-svg: per-active-stage area
// paths under a cumulative distance axis, with a crosshair + gradient/distance
// tooltip following the finger. Portage of the web SVG profile (#1041), reusing
// the shared @btp/core elevation maths.
export function ElevationProfile({
  stages,
  focusedStageIndex,
  onHover,
}: ElevationProfileProps) {
  const theme = useTheme();
  const { t } = useTranslation();
  const [width, setWidth] = useState(0);
  const [hover, setHover] = useState<{
    x: number;
    gradient: number;
    distance: number;
  } | null>(null);

  const points = useMemo(
    () => buildProfilePoints(stages, focusedStageIndex),
    [stages, focusedStageIndex],
  );
  const hasData = points.length >= 2;

  const { maxDist, displayMinEle, displayMaxEle } = useMemo(() => {
    if (!hasData) return { maxDist: 0, displayMinEle: 0, displayMaxEle: 1000 };
    const minEle = Math.min(...points.map((p) => p.ele));
    const maxEle = Math.max(...points.map((p) => p.ele));
    const dist = points[points.length - 1]?.distanceKm ?? 0;
    const elevRange = maxEle - minEle;
    // Small buffer below the baseline, generous headroom above so peaks breathe
    // (terrain sits in the bottom third, Komoot-style) — same as the web profile.
    const bufferBelow = Math.max(elevRange * 0.1, 10);
    const bufferAbove = Math.max(elevRange * 1.5, 100);
    return {
      maxDist: dist,
      displayMinEle: minEle - bufferBelow,
      displayMaxEle: maxEle + bufferAbove,
    };
  }, [points, hasData]);

  const toX = (distKm: number) =>
    PAD_L + (distKm / (maxDist || 1)) * (VW - PAD_L - PAD_R);
  const toY = (ele: number) => {
    const range = displayMaxEle - displayMinEle || 1;
    return PAD_T + (1 - (ele - displayMinEle) / range) * (VH - PAD_T - PAD_B);
  };

  const stagePaths = useMemo(() => {
    if (!hasData) return [];
    const byStage = new Map<number, typeof points>();
    for (const pt of points) {
      const arr = byStage.get(pt.stageIndex) ?? [];
      arr.push(pt);
      byStage.set(pt.stageIndex, arr);
    }
    const result: { stageIndex: number; d: string }[] = [];
    byStage.forEach((pts, stageIndex) => {
      if (pts.length < 2) return;
      const firstPt = pts[0]!;
      const lastPt = pts[pts.length - 1]!;
      const lineD = pts
        .map(
          (pt, i) =>
            `${i === 0 ? 'M' : 'L'}${toX(pt.distanceKm).toFixed(1)},${toY(pt.ele).toFixed(1)}`,
        )
        .join(' ');
      const d =
        lineD +
        ` L${toX(lastPt.distanceKm).toFixed(1)},${(VH - PAD_B).toFixed(1)}` +
        ` L${toX(firstPt.distanceKm).toFixed(1)},${(VH - PAD_B).toFixed(1)} Z`;
      result.push({ stageIndex, d });
    });
    return result;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [points, hasData, maxDist, displayMinEle, displayMaxEle]);

  const handleTouch = (e: GestureResponderEvent) => {
    if (!hasData || width === 0) return;
    const locationX = e.nativeEvent.locationX;
    const svgX = (locationX / width) * VW;
    const distKm = ((svgX - PAD_L) / (VW - PAD_L - PAD_R)) * maxDist;
    const best = findClosestProfilePoint(points, distKm);
    if (!best) return;
    onHover(best.coordIndex, best.stageIndex);
    setHover({ x: toX(best.distanceKm), gradient: best.gradient, distance: best.distanceKm });
  };

  const handleRelease = () => {
    onHover(null, null);
    setHover(null);
  };

  if (!hasData) return null;

  const flipLeft = hover !== null && hover.x > VW * 0.6;

  return (
    <View
      testID="elevation-profile"
      accessibilityLabel={t('trip.elevationProfileA11y')}
      onLayout={(e: LayoutChangeEvent) => setWidth(e.nativeEvent.layout.width)}
      onStartShouldSetResponder={() => true}
      onMoveShouldSetResponder={() => true}
      onResponderGrant={handleTouch}
      onResponderMove={handleTouch}
      onResponderRelease={handleRelease}
      onResponderTerminate={handleRelease}
      style={[
        styles.container,
        { backgroundColor: theme.colors.card, borderColor: theme.colors.border },
      ]}
    >
      <Svg
        width="100%"
        height={SVG_HEIGHT}
        viewBox={`0 0 ${VW} ${VH}`}
        preserveAspectRatio="none"
      >
        {stagePaths.map(({ stageIndex, d }) => (
          <Path
            key={stageIndex}
            d={d}
            fill={theme.colors.brand}
            fillOpacity={0.35}
            stroke={theme.colors.brand}
            strokeWidth={1.5}
            strokeOpacity={0.8}
          />
        ))}
        {hover !== null ? (
          <Line
            testID="elevation-crosshair"
            x1={hover.x}
            y1={PAD_T}
            x2={hover.x}
            y2={VH - PAD_B}
            stroke={theme.colors.mutedForeground}
            strokeWidth={1}
            opacity={0.6}
          />
        ) : null}
      </Svg>

      {hover !== null ? (
        <View
          pointerEvents="none"
          style={[
            styles.tooltip,
            {
              backgroundColor: theme.colors.card,
              borderColor: theme.colors.border,
              left: `${(hover.x / VW) * 100}%`,
              transform: [{ translateX: flipLeft ? -96 : 8 }],
            },
          ]}
        >
          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sansMedium,
              fontSize: 12,
            }}
          >
            {hover.gradient >= 0 ? '+' : ''}
            {hover.gradient.toFixed(1)}%
          </Text>
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.mono,
              fontSize: 12,
            }}
          >
            {hover.distance.toFixed(1)} km
          </Text>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    width: '100%',
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    paddingHorizontal: 8,
    paddingVertical: 4,
  },
  tooltip: {
    position: 'absolute',
    top: 4,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 6,
    paddingHorizontal: 8,
    paddingVertical: 4,
  },
});
