import { deleteStage as apiDeleteStage } from '../api/trips';
import { neighboursAt } from '@btp/core/optimistic';
import { run, type MutationContext, type OnFailure } from './mutations';

// Optimistic stage deletion (#1015): snapshot -> remove locally -> call the API.
// On success the authoritative recompute arrives over SSE and reconciles through
// the core reducers; on failure the stage is put back and the reason reported
// so the UI can toast. Now composes the shared `run` shell (#1031) so its gate
// (423 lock / offline) and rollback discipline match every other mutation; the
// dedicated wrapper is kept because a screen already consumes it by name.
export function runDeleteStage(
  tripId: string,
  index: number,
  store: MutationContext,
  onFailure: OnFailure,
): Promise<boolean> {
  const snapshot = store.stages;
  const target = snapshot[index];
  const stageId = target?.id ?? '';
  return run(
    store,
    {
      // Deleting merges into the adjacent day (no Valhalla reroute): a lock or
      // offline blocks it, but an out-of-zone trip does not.
      requiresRouting: false,
      undoable: true,
      // Put back this stage only: an edit made while the request was in flight
      // survives the refusal.
      inverse: target
        ? { kind: 'restore', stage: target, ...neighboursAt(snapshot, index) }
        : undefined,
      optimistic: () => store.deleteStageOptimistic(index),
      call: () => apiDeleteStage(tripId, stageId),
    },
    onFailure,
  );
}
