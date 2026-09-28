// Undoing an optimistic edit the server refused, shared by the web and mobile
// stores. Pure: no Zustand, no Immer.
//
// Nothing stops a second edit while the first request is still in flight, and
// the requests settle in any order. So a refusal must undo what the refused edit
// did and nothing else: restoring the pre-edit state wholesale would also wipe
// every edit made since, accepted or not. So the helpers below revert by
// identity rather than by snapshot: a stage by its id, a field only while the
// refused edit still owns it. The same inverses also repair the undo entries
// recorded after the refused edit, which captured its optimistic value.
//
// Covered by pwa/src/lib/optimistic.test.ts (core has no runner of its own).

import { renumberAfterStructuralEdit } from "./reconciliation";
import type { StageData } from "./schemas";
import { endDateFor } from "./stage-dates";

/** Where a stage sat: the stages around it, null at either end of the trip. */
export interface StageNeighbours {
  afterStageId: string | null;
  beforeStageId: string | null;
}

/** What undoes one optimistic structural edit, expressed by stage identity. */
export type StructuralInverse =
  | { kind: "remove"; stageId: string }
  | ({ kind: "restore"; stage: StageData } & StageNeighbours)
  | ({ kind: "move"; stageId: string } & StageNeighbours);

/** The neighbours of the stage at `index`, to put it back there later. */
export function neighboursAt(
  stages: StageData[],
  index: number,
): StageNeighbours {
  return {
    afterStageId: stages[index - 1]?.id ?? null,
    beforeStageId: stages[index + 1]?.id ?? null,
  };
}

/**
 * Where a stage with these neighbours goes back, which is where the server still
 * has it. Every insertion lands after some stage, so one made meanwhile after the
 * preceding stage sits in front of this one there: it goes back last if it was
 * last, else in front of the stage that followed it; failing that, first if it was
 * first, else after the stage that preceded it; failing everything, last.
 */
function slotFor(stages: StageData[], place: StageNeighbours): number {
  const indexOf = (id: string) => stages.findIndex((s) => s.id === id);
  if (place.beforeStageId === null) return stages.length;
  const before = indexOf(place.beforeStageId);
  if (before !== -1) return before;
  if (place.afterStageId === null) return 0;
  const after = indexOf(place.afterStageId);
  return after !== -1 ? after + 1 : stages.length;
}

function insertAt(
  stages: StageData[],
  stage: StageData,
  place: StageNeighbours,
): StageData[] {
  const at = slotFor(stages, place);
  return renumberAfterStructuralEdit([
    ...stages.slice(0, at),
    stage,
    ...stages.slice(at),
  ]);
}

/**
 * Apply the inverse of a refused structural edit to `stages`, leaving every other
 * stage alone. An inserted stage is removed by its provisional id, a no-op once the
 * server has replaced it; a deleted stage goes back where it was unless it is
 * already there; a moved stage goes back between its former neighbours.
 */
export function revertStructuralEdit(
  stages: StageData[],
  inverse: StructuralInverse,
): StageData[] {
  switch (inverse.kind) {
    case "remove":
      if (!stages.some((s) => s.id === inverse.stageId)) return stages;
      return renumberAfterStructuralEdit(
        stages.filter((s) => s.id !== inverse.stageId),
      );
    case "restore":
      if (stages.some((s) => s.id === inverse.stage.id)) return stages;
      return insertAt(stages, inverse.stage, inverse);
    case "move": {
      const moved = stages.find((s) => s.id === inverse.stageId);
      if (!moved) return stages;
      return insertAt(
        stages.filter((s) => s.id !== inverse.stageId),
        moved,
        inverse,
      );
    }
  }
}

/**
 * The fields to set to undo a refused edit on an undo snapshot: each field the
 * edit set goes back to its previous value, but only where the snapshot still
 * holds the refused one. A snapshot records the state just before a later edit,
 * so a field holding the refused value there got it from the refused edit.
 */
export function revertFields<T extends object>(
  target: T,
  optimistic: Partial<T>,
  previous: Partial<T>,
): Partial<T> {
  const patch: Partial<T> = {};
  for (const key of Object.keys(optimistic) as (keyof T)[]) {
    if (Object.is(target[key], optimistic[key])) patch[key] = previous[key];
  }
  return patch;
}

/**
 * Which in-flight optimistic edit last wrote each field of the live state.
 *
 * Comparing values cannot tell a refused edit's value from the same value set by a
 * newer edit, and reverting then would leave the client on a value the server no
 * longer has. So each optimistic edit claims the fields it writes, a newer claim
 * takes a field over, and a refusal reverts only the fields its claim still owns.
 */
export class FieldClaims<K extends PropertyKey = string> {
  private readonly owner = new Map<K, symbol>();

  claim(fields: Iterable<K>): symbol {
    const claim = Symbol("optimistic-edit");
    for (const field of fields) this.owner.set(field, claim);
    return claim;
  }

  /** Settle a claim, returning the fields it still owned (no newer edit wrote them). */
  release(claim: symbol): K[] {
    const owned: K[] = [];
    for (const [field, owner] of this.owner) {
      if (owner === claim) owned.push(field);
    }
    for (const field of owned) this.owner.delete(field);
    return owned;
  }

  /** Forget every claim, so a refusal that lands afterwards reverts nothing. */
  clear(): void {
    this.owner.clear();
  }
}

/** The part of a trip the end-date rule reads. */
export interface DatedTrip {
  startDate: string | null;
  endDate: string | null;
  stages: readonly unknown[];
}

type TripDates = Pick<DatedTrip, "startDate" | "endDate">;

/**
 * The date fields to set to undo a refused dates edit on the live trip, given the
 * fields its claim still owns.
 *
 * The end date is not free-standing: every structural edit re-derives it from the
 * start date and the stage count, and claims it. When one has done so since the
 * refused edit, the pre-edit end date counted the stages as they were before that
 * edit, so restoring it would leave the trip a day short or long. It is re-derived
 * from the restored start date and the current stage count instead.
 */
export function datesToRestore(
  owned: readonly string[],
  previous: TripDates,
  trip: DatedTrip,
): Partial<TripDates> {
  const patch: Partial<TripDates> = {};
  if (!owned.includes("startDate")) {
    if (owned.includes("endDate")) patch.endDate = previous.endDate;
    return patch;
  }
  patch.startDate = previous.startDate;
  if (owned.includes("endDate")) patch.endDate = previous.endDate;
  else if (previous.startDate !== null) {
    patch.endDate = endDateFor(previous.startDate, trip.stages.length);
  }
  return patch;
}

/**
 * {@link revertFields} for an undo snapshot, keeping the end-date rule: a snapshot
 * recorded after a structural edit holds an end date derived from the refused start
 * date rather than the refused end date itself, so it is re-derived from the
 * restored start date and the snapshot's own stage count.
 */
export function revertSnapshotFields<T extends DatedTrip>(
  snapshot: T,
  optimistic: Partial<T>,
  previous: Partial<T>,
): Partial<T> {
  const patch = revertFields(snapshot, optimistic, previous);
  if (
    "startDate" in patch &&
    !("endDate" in patch) &&
    "endDate" in optimistic
  ) {
    const startDate = patch.startDate ?? null;
    if (startDate !== null) {
      Object.assign(patch, {
        endDate: endDateFor(startDate, snapshot.stages.length),
      });
    }
  }
  return patch;
}
