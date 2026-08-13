import type { StageData } from '@btp/core';

// Pure derivations for the full-screen stage detail (#1039). Kept framework-free
// so the navigation bounds, per-stage stats and geometry are unit-tested without
// a React renderer.

// Parse the `[index]` route param into a non-negative integer (defaults to 0 on
// a malformed value). Bounds against the stage count are applied separately by
// clampIndex once the store is hydrated.
export function parseStageIndex(raw: string | undefined): number {
  const n = Number.parseInt(raw ?? '', 10);
  return Number.isFinite(n) && n >= 0 ? n : 0;
}

// Clamp an index into [0, count-1]; 0 when there are no stages.
export function clampIndex(index: number, count: number): number {
  if (count <= 0) return 0;
  return Math.min(Math.max(index, 0), count - 1);
}

export function hasPrevStage(index: number): boolean {
  return index > 0;
}

export function hasNextStage(index: number, count: number): boolean {
  return index < count - 1;
}

export interface StageStats {
  distanceKm: number;
  elevationGain: number;
  elevationLoss: number;
}

// Rounded per-stage stats for the detail header.
export function stageStats(stage: StageData): StageStats {
  return {
    distanceKm: Math.round(stage.distance ?? 0),
    elevationGain: Math.round(stage.elevation ?? 0),
    elevationLoss: Math.round(stage.elevationLoss ?? 0),
  };
}

// This stage's geometry as MapLibre [lon, lat] pairs (TripMap fits its bounds).
export function stageGeometryCoords(stage: StageData): [number, number][] {
  return (stage.geometry ?? []).map((p) => [p.lon, p.lat]);
}

// Map an absolute stage index to its position within the rest-day-filtered
// active stages — the index buildProfilePoints() expects as focusedStageIndex.
// Returns null when the stage is a rest day (no profile) or out of bounds.
export function activeStageIndex(
  stages: StageData[],
  index: number,
): number | null {
  const stage = stages[index];
  if (!stage || stage.isRestDay) return null;
  let active = 0;
  for (let i = 0; i < index; i++) {
    if (!stages[i]!.isRestDay) active++;
  }
  return active;
}

export interface SurfaceShare {
  surface: string;
  percent: number;
}

// Convert the optional per-stage surface breakdown (metres per OSM surface tag)
// into rounded percentages, largest first. Empty when the backend hasn't emitted
// the field yet (forward-compatible, see StageDataSchema).
export function surfaceShares(stage: StageData): SurfaceShare[] {
  const segments = stage.surfaceBreakdown ?? [];
  const total = segments.reduce((sum, s) => sum + s.lengthMeters, 0);
  if (total <= 0) return [];
  return segments
    .map((s) => ({ surface: s.surface, percent: Math.round((s.lengthMeters / total) * 100) }))
    .sort((a, b) => b.percent - a.percent);
}
