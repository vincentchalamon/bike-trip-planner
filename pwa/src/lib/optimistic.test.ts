import { describe, expect, it } from "vitest";
import type { StageData } from "@btp/core";
import {
  FieldClaims,
  datesToRestore,
  revertFields,
  revertSnapshotFields,
  revertStructuralEdit,
} from "@btp/core/optimistic";

function makeStage(dayNumber: number, distance = 50): StageData {
  return {
    id: `stage-${dayNumber}`,
    dayNumber,
    distance,
    elevation: 0,
    elevationLoss: 0,
    startPoint: { lat: 0, lon: 0, ele: 0 },
    endPoint: { lat: 0, lon: 0, ele: 0 },
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    resupply: {
      foodAtLunch: [],
      waterMorning: null,
      waterAfternoon: null,
      foodAtArrival: [],
    },
    accommodations: [],
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
  };
}

describe("revertStructuralEdit", () => {
  const stages = [makeStage(1), makeStage(2), makeStage(3)];
  const back = (result: StageData[]) => result.map((s) => s.id);

  it("puts a deleted first stage back first", () => {
    expect(
      back(
        revertStructuralEdit(stages.slice(1), {
          kind: "restore",
          stage: stages[0]!,
          afterStageId: null,
          beforeStageId: "stage-2",
        }),
      ),
    ).toEqual(["stage-1", "stage-2", "stage-3"]);
  });

  it("falls back to the preceding stage, then the end, when neighbours have gone", () => {
    const middle = {
      kind: "restore",
      stage: stages[1]!,
      afterStageId: "stage-1",
      beforeStageId: "stage-3",
    } as const;
    expect(back(revertStructuralEdit([stages[0]!], middle))).toEqual([
      "stage-1",
      "stage-2",
    ]);
    expect(back(revertStructuralEdit([makeStage(9)], middle))).toEqual([
      "stage-9",
      "stage-2",
    ]);
  });

  it("puts a deleted last stage back last, behind a stage inserted after its predecessor", () => {
    const restDay = { ...makeStage(9), id: "rest", isRestDay: true };
    expect(
      back(
        revertStructuralEdit([stages[0]!, restDay], {
          kind: "restore",
          stage: stages[1]!,
          afterStageId: "stage-1",
          beforeStageId: null,
        }),
      ),
    ).toEqual(["stage-1", "rest", "stage-2"]);
  });

  it("never duplicates a stage that is already there", () => {
    const result = revertStructuralEdit(stages, {
      kind: "restore",
      stage: stages[1]!,
      afterStageId: "stage-1",
      beforeStageId: "stage-3",
    });
    expect(result).toBe(stages);
  });

  it("is a no-op for an insertion the server already replaced", () => {
    expect(
      revertStructuralEdit(stages, { kind: "remove", stageId: "pending-x" }),
    ).toBe(stages);
  });
});

describe("revertStructuralEdit — move", () => {
  const stages = [makeStage(1), makeStage(2), makeStage(3)];

  it("puts a moved stage back between its former neighbours", () => {
    const moved = [stages[1]!, stages[2]!, stages[0]!];
    const result = revertStructuralEdit(moved, {
      kind: "move",
      stageId: "stage-1",
      afterStageId: null,
      beforeStageId: "stage-2",
    });

    expect(result.map((s) => s.id)).toEqual(["stage-1", "stage-2", "stage-3"]);
    expect(result.map((s) => s.dayNumber)).toEqual([1, 2, 3]);
  });

  it("is a no-op for a stage that has gone since", () => {
    expect(
      revertStructuralEdit(stages, {
        kind: "move",
        stageId: "gone",
        afterStageId: null,
        beforeStageId: "stage-2",
      }),
    ).toBe(stages);
  });
});

describe("revertFields", () => {
  it("reverts a field that still holds the refused value", () => {
    expect(revertFields({ a: 2, b: "x" }, { a: 2 }, { a: 1 })).toEqual({
      a: 1,
    });
  });

  it("leaves a field another edit changed since", () => {
    expect(revertFields({ a: 3 }, { a: 2 }, { a: 1 })).toEqual({});
  });

  it("only considers the fields the refused edit set", () => {
    expect(revertFields({ a: 2, b: 5 }, { a: 2 }, { a: 1, b: 0 })).toEqual({
      a: 1,
    });
  });
});

describe("FieldClaims", () => {
  it("hands a field over to the newer claim", () => {
    const claims = new FieldClaims();
    const older = claims.claim(["a", "b"]);
    claims.claim(["b"]);

    expect(claims.release(older)).toEqual(["a"]);
  });

  it("owns nothing once released or cleared", () => {
    const claims = new FieldClaims();
    const settled = claims.claim(["a"]);
    claims.release(settled);
    const pending = claims.claim(["b"]);
    claims.clear();

    expect(claims.release(settled)).toEqual([]);
    expect(claims.release(pending)).toEqual([]);
  });
});

describe("datesToRestore", () => {
  const previous = { startDate: "2026-10-01", endDate: "2026-10-03" };
  const trip = (count: number) => ({
    startDate: "2026-11-01",
    endDate: "2026-11-04",
    stages: Array.from({ length: count }),
  });

  it("restores both dates it still owns", () => {
    expect(datesToRestore(["startDate", "endDate"], previous, trip(4))).toEqual(
      previous,
    );
  });

  it("re-derives the end date a structural edit took over", () => {
    expect(datesToRestore(["startDate"], previous, trip(4))).toEqual({
      startDate: "2026-10-01",
      endDate: "2026-10-04",
    });
  });

  it("touches nothing a newer dates edit owns", () => {
    expect(datesToRestore([], previous, trip(4))).toEqual({});
  });
});

describe("revertSnapshotFields", () => {
  it("re-derives an end date a structural edit derived from the refused start", () => {
    const snapshot = {
      startDate: "2026-11-01",
      endDate: "2026-11-04",
      stages: Array.from({ length: 4 }),
    };
    expect(
      revertSnapshotFields(
        snapshot,
        { startDate: "2026-11-01", endDate: "2026-11-03" },
        { startDate: "2026-10-01", endDate: "2026-10-03" },
      ),
    ).toEqual({ startDate: "2026-10-01", endDate: "2026-10-04" });
  });
});
