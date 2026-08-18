import { forwardRef, useMemo } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import Svg, { Circle, Path, Polyline } from 'react-native-svg';
import type { StageData } from '@btp/core';
import { buildProfilePoints } from '@btp/core/elevation';
import {
  computeEstimatedBudget,
  computeOverallDifficulty,
  computeTripTotals,
  type DifficultyLabels,
} from '../../lib/share';

// Off-screen infographic card captured to PNG by react-native-view-shot (#1048).
// RN adaptation of the web canvas (pwa/src/lib/infographic.ts): same content
// (title, route, per-stage stats, elevation profile, branding) rendered with
// react-native-svg on a solid dark card instead of OSM tiles on a <canvas>.

export const CARD_WIDTH = 340;
export const CARD_HEIGHT = 560;
const PADDING = 20;
const MAP_HEIGHT = 220;
const PROFILE_HEIGHT = 90;

// Per-stage palette (mirror of infographic.ts STAGE_PALETTE).
const STAGE_PALETTE = [
  '#38bdf8',
  '#f97316',
  '#a78bfa',
  '#4ade80',
  '#fb7185',
  '#facc15',
  '#22d3ee',
  '#c084fc',
];

function stageColor(index: number): string {
  return STAGE_PALETTE[index % STAGE_PALETTE.length]!;
}

const mercY = (lat: number) => {
  const rad = (lat * Math.PI) / 180;
  return (1 - Math.log(Math.tan(rad) + 1 / Math.cos(rad)) / Math.PI) / 2;
};

/**
 * Min/max of a numeric array via a single reduce. NOT `Math.min(...arr)`: a
 * multi-day trip flattens several thousand decimated points into one array, and
 * spreading that many arguments overflows Hermes' stricter argument limit
 * (RangeError: Maximum call stack size exceeded). Mirrors the web infographic's
 * loop-based bounds. Callers guarantee a non-empty array.
 */
export function minMax(values: number[]): { min: number; max: number } {
  return values.reduce(
    (acc, v) => ({
      min: v < acc.min ? v : acc.min,
      max: v > acc.max ? v : acc.max,
    }),
    { min: values[0]!, max: values[0]! },
  );
}

interface RoutePolyline {
  points: string;
  color: string;
}

interface ProjectedRoute {
  polylines: RoutePolyline[];
  start: { x: number; y: number } | null;
  end: { x: number; y: number } | null;
}

/** Project the trip route into the map box (WebMercator, fit + centered). */
export function projectRoute(
  stages: StageData[],
  w: number,
  h: number,
): ProjectedRoute {
  const active = stages.filter((s) => !s.isRestDay && s.geometry.length >= 2);
  const all = active.flatMap((s) => s.geometry);
  if (all.length < 2) {
    return { polylines: [], start: null, end: null };
  }
  const xs = all.map((p) => (p.lon + 180) / 360);
  const ys = all.map((p) => mercY(p.lat));
  const { min: minX, max: maxX } = minMax(xs);
  const { min: minY, max: maxY } = minMax(ys);
  const spanX = maxX - minX || 1e-6;
  const spanY = maxY - minY || 1e-6;
  const scale = Math.min(w / spanX, h / spanY) * 0.92;
  const offX = (w - spanX * scale) / 2;
  const offY = (h - spanY * scale) / 2;
  const toXY = (lon: number, lat: number) => ({
    x: offX + ((lon + 180) / 360 - minX) * scale,
    y: offY + (mercY(lat) - minY) * scale,
  });

  const polylines = active.map((stage, i) => ({
    color: stageColor(i),
    points: stage.geometry
      .map((p) => {
        const { x, y } = toXY(p.lon, p.lat);
        return `${x.toFixed(1)},${y.toFixed(1)}`;
      })
      .join(' '),
  }));

  const firstGeom = active[0]!.geometry;
  const lastGeom = active[active.length - 1]!.geometry;
  const first = firstGeom[0]!;
  const last = lastGeom[lastGeom.length - 1]!;
  return {
    polylines,
    start: toXY(first.lon, first.lat),
    end: toXY(last.lon, last.lat),
  };
}

function formatDateRange(start: string | null, end: string | null): string {
  const fmt = (iso: string) =>
    new Date(iso.includes('T') ? iso : `${iso}T00:00:00`).toLocaleDateString(
      undefined,
      { day: 'numeric', month: 'short', year: 'numeric' },
    );
  if (start && end) return `${fmt(start)} → ${fmt(end)}`;
  if (start) return fmt(start);
  return '';
}

export interface InfographicLabels {
  distance: string;
  elevation: string;
  dates: string;
  budget: string;
  difficulty: DifficultyLabels & { label: string };
  powered: string;
}

interface ShareInfographicProps {
  title: string;
  stages: StageData[];
  startDate: string | null;
  endDate: string | null;
  labels: InfographicLabels;
}

// forwardRef so the parent can hand this View to captureRef (share-image.ts).
export const ShareInfographic = forwardRef<View, ShareInfographicProps>(
  function ShareInfographic({ title, stages, startDate, endDate, labels }, ref) {
    const totals = useMemo(() => computeTripTotals(stages), [stages]);
    const budget = useMemo(() => computeEstimatedBudget(stages), [stages]);
    const difficulty = useMemo(
      () => computeOverallDifficulty(stages, labels.difficulty),
      [stages, labels.difficulty],
    );
    const mapW = CARD_WIDTH - PADDING * 2;
    const route = useMemo(
      () => projectRoute(stages, mapW, MAP_HEIGHT),
      [stages, mapW],
    );
    const profile = useMemo(() => buildProfilePoints(stages, null), [stages]);

    const activeCount = stages.filter((s) => !s.isRestDay).length;
    const datesValue = formatDateRange(startDate, endDate) || `${activeCount}`;

    const stats: Array<{ icon: string; label: string; value: string; color: string }> = [
      {
        icon: '🚴',
        label: labels.distance,
        value: `${Math.round(totals.totalDistance)} km`,
        color: '#38bdf8',
      },
      {
        icon: '⛰️',
        label: labels.elevation,
        value: `⬆ ${Math.round(totals.totalElevation)}m ⬇ ${Math.round(totals.totalElevationLoss)}m`,
        color: '#f97316',
      },
      {
        icon: '📅',
        label: labels.dates,
        value: datesValue,
        color: '#a78bfa',
      },
      {
        icon: '💶',
        label: labels.budget,
        value:
          budget.min > 0 || budget.max > 0
            ? `${Math.round(budget.min)}–${Math.round(budget.max)}€`
            : '—',
        color: '#4ade80',
      },
      {
        icon: '💪',
        label: labels.difficulty.label,
        value: difficulty.label,
        color: difficulty.color,
      },
    ];

    const profilePath = useMemo(() => {
      if (profile.length < 2) return null;
      const eles = profile.map((p) => p.ele);
      const { min: minEle, max: maxEle } = minMax(eles);
      const range = maxEle - minEle;
      const displayMin = minEle - Math.max(range * 0.1, 10);
      const displayMax = maxEle + Math.max(range * 1.5, 100);
      const dRange = displayMax - displayMin || 1;
      const w = CARD_WIDTH - PADDING * 2;
      const maxDist = profile[profile.length - 1]!.distanceKm || 1;
      const toX = (km: number) => (km / maxDist) * w;
      const toY = (ele: number) =>
        PROFILE_HEIGHT - ((ele - displayMin) / dRange) * PROFILE_HEIGHT;
      const line = profile
        .map(
          (p, i) =>
            `${i === 0 ? 'M' : 'L'}${toX(p.distanceKm).toFixed(1)},${toY(p.ele).toFixed(1)}`,
        )
        .join(' ');
      return `${line} L${w.toFixed(1)},${PROFILE_HEIGHT} L0,${PROFILE_HEIGHT} Z`;
    }, [profile]);

    return (
      <View ref={ref} collapsable={false} style={styles.card} testID="share-infographic">
        <Text style={styles.title} numberOfLines={1}>
          {title}
        </Text>
        <View style={styles.separator} />

        <View style={styles.map}>
          {route.polylines.length > 0 ? (
            <Svg width={mapW} height={MAP_HEIGHT}>
              {route.polylines.map((pl, i) => (
                <Polyline
                  key={i}
                  points={pl.points}
                  fill="none"
                  stroke={pl.color}
                  strokeWidth={2.5}
                  strokeLinejoin="round"
                  strokeLinecap="round"
                />
              ))}
              {route.start ? (
                <Circle cx={route.start.x} cy={route.start.y} r={5} fill="#22c55e" />
              ) : null}
              {route.end ? (
                <Circle cx={route.end.x} cy={route.end.y} r={5} fill="#ef4444" />
              ) : null}
            </Svg>
          ) : null}
        </View>

        <View style={styles.stats}>
          {stats.map((stat) => (
            <View key={stat.label} style={styles.statRow}>
              <Text style={styles.statIcon}>{stat.icon}</Text>
              <View style={styles.statText}>
                <Text style={styles.statLabel}>{stat.label}</Text>
                <Text style={[styles.statValue, { color: stat.color }]} numberOfLines={1}>
                  {stat.value}
                </Text>
              </View>
            </View>
          ))}
        </View>

        {profilePath ? (
          <Svg width={CARD_WIDTH - PADDING * 2} height={PROFILE_HEIGHT} style={styles.profile}>
            <Path d={profilePath} fill="#38bdf8" fillOpacity={0.25} stroke="#38bdf8" strokeWidth={1.5} />
          </Svg>
        ) : null}

        <Text style={styles.footer}>© {labels.powered}</Text>
      </View>
    );
  },
);

const styles = StyleSheet.create({
  card: {
    width: CARD_WIDTH,
    height: CARD_HEIGHT,
    padding: PADDING,
    backgroundColor: '#0f172a',
  },
  title: {
    color: '#ffffff',
    fontSize: 20,
    fontWeight: '700',
  },
  separator: {
    height: 1,
    backgroundColor: '#334155',
    marginTop: 10,
    marginBottom: 10,
  },
  map: {
    height: MAP_HEIGHT,
    backgroundColor: '#1e293b',
    borderRadius: 8,
    overflow: 'hidden',
  },
  stats: {
    marginTop: 12,
    gap: 8,
  },
  statRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  statIcon: {
    fontSize: 16,
    width: 22,
  },
  statText: {
    flex: 1,
  },
  statLabel: {
    color: '#64748b',
    fontSize: 10,
  },
  statValue: {
    fontSize: 13,
    fontWeight: '700',
  },
  profile: {
    marginTop: 12,
  },
  footer: {
    color: '#475569',
    fontSize: 10,
    marginTop: 'auto',
  },
});
