import type { StageData } from '@btp/core';
import { deleteStage as apiDeleteStage } from '../api/trips';

export type DeleteFailure = 'locked' | 'error';

// The store surface the deletion drives.
export interface DeleteStageStore {
  isLocked: boolean;
  stages: StageData[];
  deleteStageOptimistic: (index: number) => void;
  setStages: (stages: StageData[]) => void;
}

// Optimistic stage deletion (#1015): snapshot -> remove locally -> call the API.
// On success the authoritative recompute arrives over SSE and reconciles through
// the core reducers; on failure (including 423 when the trip is locked) the
// snapshot is restored and the reason reported so the UI can toast. Extracted
// from the screen so the optimistic/rollback/423 branches are unit-testable.
export async function runDeleteStage(
  tripId: string,
  index: number,
  store: DeleteStageStore,
  onFailure: (reason: DeleteFailure) => void,
): Promise<void> {
  // A started trip is read-only; skip the optimistic mutation entirely.
  if (store.isLocked) {
    onFailure('locked');
    return;
  }

  const snapshot = store.stages;
  store.deleteStageOptimistic(index);

  try {
    const { ok, status } = await apiDeleteStage(tripId, index);
    if (!ok) {
      store.setStages(snapshot);
      onFailure(status === 423 ? 'locked' : 'error');
    }
    // On success, the SSE trip_ready / stage_updated event replaces the
    // optimistic list with the reconciled authoritative state.
  } catch {
    store.setStages(snapshot);
    onFailure('error');
  }
}
