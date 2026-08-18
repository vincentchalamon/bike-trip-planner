import type { StageData } from '@btp/core';

// How long a post-recompute diff highlight stays lit before it auto-clears.
export const DIFF_TTL_MS = 4000;

// A stage is considered "changed" by a destructive recompute when any of the
// rider-visible fields moved. Distances/elevations are compared rounded (the UI
// renders them rounded, so sub-unit jitter must not light a highlight).
function stageChanged(a: StageData, b: StageData): boolean {
  return (
    Math.round(a.distance ?? 0) !== Math.round(b.distance ?? 0) ||
    Math.round(a.elevation ?? 0) !== Math.round(b.elevation ?? 0) ||
    (a.dayNumber ?? 0) !== (b.dayNumber ?? 0) ||
    a.isRestDay !== b.isRestDay ||
    a.endPoint?.lat !== b.endPoint?.lat ||
    a.endPoint?.lon !== b.endPoint?.lon
  );
}

/**
 * Indices of the stages that differ between the pre-recompute baseline and the
 * authoritative result. A count change (a re-split added or removed days) marks
 * every resulting index changed. Pure and framework-free so the diff-highlight
 * logic is one testable unit shared by the store (#1046).
 */
export function diffStageIndices(
  before: StageData[],
  after: StageData[],
): Set<number> {
  const changed = new Set<number>();
  if (before.length !== after.length) {
    after.forEach((_, i) => changed.add(i));
    return changed;
  }
  after.forEach((stage, i) => {
    const prev = before[i];
    if (!prev || stageChanged(prev, stage)) changed.add(i);
  });
  return changed;
}
