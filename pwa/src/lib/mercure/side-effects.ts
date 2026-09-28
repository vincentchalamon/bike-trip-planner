import type { MercureEvent } from "@btp/core/mercure";
import type { StageData } from "@btp/core";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { toast } from "@/components/ui/sonner";
import { computeStageDiff } from "@/lib/mercure/stage-diff";
import { resolveStageLabels } from "@/lib/mercure/stage-labels";

/** How long a changed field stays highlighted after a `stage_updated` (#1330). */
const DIFF_HIGHLIGHT_MS = 3000;

/**
 * What a side effect is allowed to see: the two snapshots around the reduction, and the two
 * per-subscription resources it may not outlive.
 *
 * Both are scoped to one subscription rather than module-global, and that is load-bearing: the
 * `signal` is aborted on unmount or trip-switch so a late reverse-geocode reply cannot write
 * another trip's labels (#787), and `timers` is cleared on teardown so a highlight timer from
 * trip A never fires against trip B.
 */
export interface SideEffectContext {
  /** Stages as they were BEFORE the reducer ran — the `stage_updated` diff needs both. */
  previousStages: StageData[];
  /** Stages as they are after it. */
  currentStages: StageData[];
  signal: AbortSignal;
  timers: Map<string, ReturnType<typeof setTimeout>>;
}

type SideEffect<K extends MercureEvent["type"]> = (
  event: Extract<MercureEvent, { type: K }>,
  context: SideEffectContext,
) => void;

/** Geocode every stage still missing a label, keeping each one's index in the store. */
function labelStagesMissingOne(
  stages: StageData[],
  signal: AbortSignal,
  only?: ReadonlySet<string>,
): void {
  const pending = stages
    .map((stage, index) => ({ stage, index }))
    .filter(({ stage }) =>
      only
        ? only.has(stage.id)
        : stage.startLabel === null || stage.endLabel === null,
    );

  if (pending.length === 0) return;

  void resolveStageLabels(
    pending.map(({ stage }) => stage),
    pending.map(({ index }) => index),
    signal,
  );
}

/**
 * What each server-pushed event does to the INTERFACE — and nothing else (#1330).
 *
 * State reconciliation is not here: it belongs to the shared reducer in
 * `@btp/core/reconciliation`, which web and mobile both run (ADR-055), and the recompute
 * markers and `computationStatus` are already settled by the time anything below runs. What is
 * left is what only a browser has: toasts, spinners, reverse geocoding and the transient
 * highlight.
 *
 * A registry rather than the `switch` this replaces, for two reasons. One entry can be read,
 * changed and tested without scrolling past nine others. And the shape is honest about its
 * coverage: this map is deliberately **partial** — most of the ~25 event types have no visible
 * consequence beyond the reduction, and the `switch` said the same thing by having no `default`,
 * only less legibly.
 *
 * ⚠ Nothing checks that a new event type appears here, and nothing should: a missing entry is
 * the normal case. The completeness that does matter — every published event existing in the
 * shared contract — is pinned elsewhere, by `MercureEventContractTest` against `core/mercure.ts`.
 */
const SIDE_EFFECTS: {
  [K in MercureEvent["type"]]?: SideEffect<K>;
} = {
  stages_computed: (event, { previousStages, currentStages, signal }) => {
    const { affectedStageIds } = event.data;

    // Partial update: only the affected stages had their labels reset. A full replace has to
    // look at every stage instead, because which ones kept their endpoints is not knowable here.
    const partial =
      affectedStageIds &&
      affectedStageIds.length > 0 &&
      previousStages.length > 0;

    labelStagesMissingOne(
      currentStages,
      signal,
      partial ? new Set(affectedStageIds) : undefined,
    );
  },

  weather_fetched: () => {
    // Weather enrichment landed — resolve its per-block spinner (ADR-043).
    useUiStore.getState().setBlockStatus("weather", "done");
  },

  accommodations_found: () => {
    // Settle the "Recherche d'hébergements" spinner as soon as results land: a standalone scan
    // (expand-radius / 409 re-scan) never emits a terminal trip_ready/trip_complete (recette #649).
    useUiStore.getState().setAccommodationScanning(false);
  },

  route_segment_recalculated: () => {
    useUiStore.getState().setProcessing(false);
  },

  trip_complete: () => {
    // Terminal completion — settle the global overlays and the weather block spinner (safety
    // net in case weather_fetched never fired).
    const ui = useUiStore.getState();
    ui.setBlockStatus("weather", "done");
    ui.setProcessing(false);
    ui.setAccommodationScanning(false);
  },

  trip_ready: (_event, { currentStages, signal }) => {
    // The terminal enrichment payload landed — settle the overlays, then geocode any stage
    // still missing a label.
    const ui = useUiStore.getState();
    ui.setBlockStatus("weather", "done");
    ui.setProcessing(false);
    ui.setAccommodationScanning(false);

    labelStagesMissingOne(currentStages, signal);
  },

  stage_updated: (event, { previousStages, currentStages, signal, timers }) => {
    const { stageId } = event.data;
    const previous = previousStages.find((stage) => stage.id === stageId);
    const current = currentStages.find((stage) => stage.id === stageId);

    // Transient diff-highlight of the changed fields (reads pre vs post state).
    if (previous && current) {
      const changed = computeStageDiff(previous, current);

      if (changed.size > 0) {
        const running = timers.get(stageId);
        if (running !== undefined) clearTimeout(running);

        useTripStore.getState().setStageDiff(stageId, changed);

        timers.set(
          stageId,
          setTimeout(() => {
            useTripStore.getState().clearStageDiff(stageId);
            timers.delete(stageId);
          }, DIFF_HIGHLIGHT_MS),
        );
      }
    }

    // A batch/inline recompute (Mode 2) never emits a terminal trip_complete, so once the last
    // recomputing stage settles, clear the global processing overlay here — otherwise it spins
    // forever (recette #649). Tied to recomputingStages so the initial full analysis keeps
    // relying on trip_ready.
    if (useTripStore.getState().recomputingStages.size === 0) {
      useUiStore.getState().setProcessing(false);
    }

    // Labels may have been wiped if endpoints moved — refresh if needed.
    const index = currentStages.findIndex((stage) => stage.id === stageId);
    if (
      current &&
      index !== -1 &&
      (current.startLabel === null || current.endLabel === null)
    ) {
      void resolveStageLabels([current], [index], signal);
    }
  },

  validation_error: (event) => {
    const ui = useUiStore.getState();
    toast.error(event.data.message);
    ui.setProcessing(false);
    ui.setAccommodationScanning(false);
  },

  computation_error: (event) => {
    const ui = useUiStore.getState();
    toast.error(`Computation failed: ${event.data.message}`);

    // Map the failed computation onto its per-block spinner so the matching block surfaces an
    // error + retry affordance (ADR-043). Weather/wind → weather. Other computations have no
    // dedicated block.
    const { computation, retryable } = event.data;
    if (computation === "weather" || computation === "wind") {
      ui.setBlockStatus("weather", "failed");
    }

    if (!retryable) {
      ui.setProcessing(false);
      ui.setAccommodationScanning(false);
    }
  },

  computations_superseded: (event) => {
    // No toast: nothing failed, and the edit that superseded these is the user's own. The
    // spinner has to stop all the same — until ADR-073 these computations stayed `pending` for
    // good and it never did.
    //
    // Cleared rather than marked failed: the block has no answer, which is what `null` means
    // here. The reducer has already recorded `superseded` in `computationStatus`, where the
    // outcome belongs. `setProcessing` is left alone — the generation that superseded them is
    // running right now.
    const { computations } = event.data;
    if (computations.includes("weather") || computations.includes("wind")) {
      useUiStore.getState().setBlockStatus("weather", null);
    }
  },
};

/**
 * Run the interface's reaction to one event, if it has one.
 *
 * The cast is the price of the registry and is contained here, in one line, rather than paid at
 * each entry: TypeScript cannot carry the correlation between `event.type` and the handler it
 * just looked up by that same key. Every handler is still written against its own narrowed
 * event type, which is where the checking that matters happens.
 */
export function applySideEffect(
  event: MercureEvent,
  context: SideEffectContext,
): void {
  const effect = SIDE_EFFECTS[event.type] as
    SideEffect<MercureEvent["type"]> | undefined;

  effect?.(event, context);
}
