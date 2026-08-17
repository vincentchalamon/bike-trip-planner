// Pure elevation-profile maths shared by the web and mobile clients (#1041).
// Extracted (same semantics) from the web ElevationProfile component
// (pwa/src/components/Map/ElevationProfile.tsx) so both platforms build the
// identical cumulative distance/altitude profile and hover mapping. No React /
// React Native / SVG dependency — only the `StageData` shape from schemas.
//
// The web component still owns its own copy for now; this module is the
// framework-free source both consumers are meant to converge on (mobile does so
// in #1041; the web migration is deliberately out of scope here).

import type { StageData } from "./schemas";

/** Haversine distance between two lat/lon points, in kilometres. */
export function haversineKm(
  lat1: number,
  lon1: number,
  lat2: number,
  lon2: number,
): number {
  const R = 6371;
  const dLat = ((lat2 - lat1) * Math.PI) / 180;
  const dLon = ((lon2 - lon1) * Math.PI) / 180;
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((lat1 * Math.PI) / 180) *
      Math.cos((lat2 * Math.PI) / 180) *
      Math.sin(dLon / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/** One sampled point of the cumulative elevation profile. */
export interface ProfilePoint {
  /** Cumulative distance from the trip (or focused stage) start, in km. */
  distanceKm: number;
  /** Elevation at this point, in metres. */
  ele: number;
  /** Slope from the previous point to this one, in percent. 0 for the first. */
  gradient: number;
  /** Index into the rest-day-filtered active stages. */
  stageIndex: number;
  /** Index into that stage's `geometry` array. */
  coordIndex: number;
}

/**
 * Build a flat array of cumulative profile points for one or all stages. Rest
 * days are excluded and `stageIndex` refers to the position within the
 * rest-day-filtered active stages (so it maps directly to the map's active-stage
 * indexing). With `focusedStageIndex` set, only that active stage is profiled;
 * with `null`, every active stage is concatenated into one continuous profile.
 */
export function buildProfilePoints(
  stages: StageData[],
  focusedStageIndex: number | null,
): ProfilePoint[] {
  const activeStages = stages.filter((s) => !s.isRestDay);
  const entries: { stage: StageData; stageIndex: number }[] =
    focusedStageIndex !== null
      ? activeStages[focusedStageIndex]
        ? [{ stage: activeStages[focusedStageIndex]!, stageIndex: focusedStageIndex }]
        : []
      : activeStages.map((stage, idx) => ({ stage, stageIndex: idx }));

  const points: ProfilePoint[] = [];
  let cumulativeKm = 0;
  let prevEle: number | null = null;
  let prevDistKm: number | null = null;

  for (const { stage, stageIndex } of entries) {
    const coords = stage.geometry;
    if (coords.length < 2) continue;

    for (let ci = 0; ci < coords.length; ci++) {
      const currEle = coords[ci]!.ele;
      let distKm = cumulativeKm;

      if (ci > 0) {
        const prev = coords[ci - 1]!;
        const curr = coords[ci]!;
        const lastPoint = points[points.length - 1];
        distKm =
          (lastPoint?.distanceKm ?? cumulativeKm) +
          haversineKm(prev.lat, prev.lon, curr.lat, curr.lon);
      }

      let gradient = 0;
      if (prevEle !== null && prevDistKm !== null) {
        const deltaKm = distKm - prevDistKm;
        if (deltaKm > 0) gradient = ((currEle - prevEle) / (deltaKm * 1000)) * 100;
      }
      prevEle = currEle;
      prevDistKm = distKm;

      points.push({ distanceKm: distKm, ele: currEle, gradient, stageIndex, coordIndex: ci });
    }

    cumulativeKm = points[points.length - 1]?.distanceKm ?? cumulativeKm;
  }

  return points;
}

/**
 * Binary search for the profile point whose `distanceKm` is closest to `target`.
 * Returns `undefined` for an empty array. Assumes `points` is sorted by
 * `distanceKm` ascending (as {@link buildProfilePoints} produces).
 */
export function findClosestProfilePoint<T extends { distanceKm: number }>(
  points: T[],
  target: number,
): T | undefined {
  if (points.length === 0) return undefined;
  let lo = 0;
  let hi = points.length - 1;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (points[mid]!.distanceKm < target) lo = mid + 1;
    else hi = mid;
  }
  if (
    lo > 0 &&
    Math.abs(points[lo - 1]!.distanceKm - target) <
      Math.abs(points[lo]!.distanceKm - target)
  ) {
    return points[lo - 1]!;
  }
  return points[lo]!;
}

/**
 * Two-point [lon, lat] stretch surrounding a hovered profile point, ready to
 * feed the map's `highlightedSegment`. `stageIndex` is an active-stage index
 * (rest days filtered, matching {@link buildProfilePoints}). Returns the hovered
 * geometry point plus its next neighbour (or previous, at the stage end), or an
 * empty array when the point cannot be located.
 */
export function profileHighlightSegment(
  stages: StageData[],
  stageIndex: number,
  coordIndex: number,
): [number, number][] {
  const stage = stages.filter((s) => !s.isRestDay)[stageIndex];
  if (!stage) return [];
  const coords = stage.geometry;
  const a = coords[coordIndex];
  if (!a) return [];
  const b = coords[coordIndex + 1] ?? coords[coordIndex - 1];
  if (!b) return [];
  return [
    [a.lon, a.lat],
    [b.lon, b.lat],
  ];
}
