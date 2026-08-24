import { deleteStage as apiDeleteStage } from '../api/trips';
import { run, type MutationContext, type OnFailure } from './mutations';

// Optimistic stage deletion (#1015): snapshot -> remove locally -> call the API.
// On success the authoritative recompute arrives over SSE and reconciles through
// the core reducers; on failure the snapshot is restored and the reason reported
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
  return run(
    store,
    {
      // Deleting merges into the adjacent day (no Valhalla reroute): a lock or
      // offline blocks it, but an out-of-zone trip does not.
      requiresRouting: false,
      undoable: true,
      optimistic: () => store.deleteStageOptimistic(index),
      rollback: () => store.setStages(snapshot),
      call: () => apiDeleteStage(tripId, index),
    },
    onFailure,
  );
}
