import { buildProfilePoints } from '@btp/core/elevation';
import type { StageData } from '@btp/core';

// Group an integer into space-separated thousands (fr/en convention, "5 240").
// Manual grouping avoids depending on the runtime's Intl/ICU build.
export function groupThousands(value: number): string {
  return Math.round(value)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

export interface ProfileSummary {
  distanceKm: number;
  gain: number;
  startEle: number;
  endEle: number;
  maxEle: number;
}

// Totals for the map tab's profile header/axis: distance + endpoint/max
// elevations come from the same shared profile maths the SVG uses; the gain sums
// each riding stage's climb. Null until the route has at least two profile
// points (nothing to frame).
export function computeProfileSummary(stages: StageData[]): ProfileSummary | null {
  const points = buildProfilePoints(stages, null);
  const gain = stages.reduce((sum, s) => sum + (s.isRestDay ? 0 : s.elevation), 0);
  if (points.length < 2) return null;
  const eles = points.map((p) => p.ele);
  return {
    distanceKm: points[points.length - 1]!.distanceKm,
    gain,
    startEle: points[0]!.ele,
    endEle: points[points.length - 1]!.ele,
    maxEle: Math.max(...eles),
  };
}
