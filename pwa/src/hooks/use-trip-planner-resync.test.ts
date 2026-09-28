import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { EMPTY_RESUPPLY, type StageData } from "@btp/core";

// Only the HTTP boundary is faked: parseApiError and the rest of the client keep their real
// behaviour, so the status→"stale" mapping is exercised rather than restated.
const holder = vi.hoisted(() => ({
  status: 200,
  offline: false,
  // When set, each request waits until the test settles it with a status, so
  // overlapping requests can be made to land in any order.
  deferred: null as ((status: number) => void)[] | null,
}));

vi.mock("@/lib/api/client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/api/client")>();
  const respond = async () => {
    if (holder.offline) throw new TypeError("Failed to fetch");
    if (holder.deferred) {
      const queue = holder.deferred;
      return new Promise<{
        data: undefined;
        error: object | undefined;
        response: Response;
      }>((resolve) =>
        queue.push((status) =>
          resolve({
            data: undefined,
            error: status < 400 ? undefined : {},
            response: new Response(null, { status }),
          }),
        ),
      );
    }

    return {
      data: undefined,
      error: {},
      response: new Response(null, { status: holder.status }),
    };
  };

  return {
    ...actual,
    apiClient: { DELETE: respond, PATCH: respond, POST: respond },
    // Bound to the real client inside the module, so faked at its own boundary.
    applyBatchRecompute: async () => (await respond()).response.ok,
  };
});

vi.mock("@/hooks/use-mercure", () => ({ useMercure: () => {} }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("next-intl", () => ({ useTranslations: () => (key: string) => key }));
vi.mock("@/components/ui/sonner", () => ({
  toast: { error: vi.fn(), success: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

import { useTripPlanner } from "./use-trip-planner";
import { useTripStore, useTripTemporalStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";

function stage(dayNumber: number): StageData {
  const point = { lat: 0, lon: 0, ele: 0 };

  return {
    id: `stage-${dayNumber}`,
    dayNumber,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: point,
    endPoint: point,
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    resupply: EMPTY_RESUPPLY,
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 10,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
  };
}

beforeEach(() => {
  holder.offline = false;
  holder.deferred = null;
  useTripStore.getState().clearTrip();
  useTripStore.setState({
    trip: { id: "t1", title: "Trip", sourceUrl: "" },
    stages: [stage(1), stage(2), stage(3)],
  });
});

/**
 * The client half of ADR-067: a refusal for staleness re-reads the trip.
 *
 * The plumbing below it is covered elsewhere (the header and ETag in `client.test.ts`, the
 * counter in the UI store), but the wiring between "the server refused this as stale" and
 * "re-fetch the trip" is the behaviour users actually get, and a dropped `type === "stale"`
 * check would fail nothing without this (#1292 review).
 */
describe("useTripPlanner — a stale refusal asks for a resync (ADR-067)", () => {
  it.each([412, 428])("bumps the resync token on a %i", async (status) => {
    holder.status = status;
    const before = useUiStore.getState().resyncToken;
    const { result } = renderHook(() => useTripPlanner());

    await act(async () => {
      await result.current.handleDeleteStage(1);
    });

    expect(useUiStore.getState().resyncToken).toBe(before + 1);
  });

  it("bumps the resync token on a refused rest-day insertion", async () => {
    holder.status = 412;
    const before = useUiStore.getState().resyncToken;
    const { result } = renderHook(() => useTripPlanner());

    await act(async () => {
      await result.current.handleInsertRestDay(0);
    });

    expect(useUiStore.getState().resyncToken).toBe(before + 1);
    expect(useTripStore.getState().stages).toHaveLength(3);
  });

  it("leaves it alone on a refusal that is not about staleness", async () => {
    holder.status = 422;
    const before = useUiStore.getState().resyncToken;
    const { result } = renderHook(() => useTripPlanner());

    await act(async () => {
      await result.current.handleDeleteStage(1);
    });

    expect(useUiStore.getState().resyncToken).toBe(before);
  });
});

describe("useTripPlanner — a refused title is rolled back", () => {
  it("restores the previous title and asks for a resync on a 412", async () => {
    holder.status = 412;
    const before = useUiStore.getState().resyncToken;
    const { result } = renderHook(() => useTripPlanner());

    await act(async () => {
      await result.current.handleTitleChange("Renamed");
    });

    expect(useTripStore.getState().trip?.title).toBe("Trip");
    expect(useUiStore.getState().resyncToken).toBe(before + 1);
  });
});

describe("useTripPlanner — a refused trip setting is rolled back", () => {
  it.each([
    ["refused", () => (holder.status = 422)],
    ["unreachable", () => (holder.offline = true)],
  ])("restores the dates and drops the undo entry when %s", async (_, fail) => {
    fail();
    useTripStore.setState({ startDate: "2026-10-01", endDate: "2026-10-03" });
    const { result } = renderHook(() => useTripPlanner());

    await act(async () => {
      await result.current.handleDatesChange("2026-11-01", "2026-11-03");
    });

    expect(useTripStore.getState().startDate).toBe("2026-10-01");
    expect(useTripStore.getState().endDate).toBe("2026-10-03");
    expect(useTripTemporalStore.getState().canUndo).toBe(false);
  });

  it("restores the departure hour", async () => {
    holder.status = 422;
    useTripStore.setState({ departureHour: 8 });
    const { result } = renderHook(() => useTripPlanner());

    await act(async () => {
      await result.current.handleDepartureHourChange(6);
    });

    expect(useTripStore.getState().departureHour).toBe(8);
  });

  it("restores the e-bike mode and the terrain alerts it cleared", async () => {
    holder.status = 422;
    const terrain = {
      type: "warning" as const,
      message: "Steep",
      lat: 0,
      lon: 0,
      group: "terrain",
    };
    useTripStore.setState({
      ebikeMode: true,
      stages: [{ ...stage(1), alerts: [terrain] }, stage(2)],
    });
    const { result } = renderHook(() => useTripPlanner());

    await act(async () => {
      await result.current.handleEbikeModeChange(false);
    });

    expect(useTripStore.getState().ebikeMode).toBe(true);
    expect(useTripStore.getState().stages[0]?.alerts).toEqual([terrain]);
  });

  it("restores the pacing and drops the undo entry", async () => {
    holder.status = 422;
    useTripStore.setState({ fatigueFactor: 0.8, maxDistancePerDay: 80 });
    const { result } = renderHook(() => useTripPlanner());

    await act(async () => {
      await result.current.handlePacingCommit(0.9, 100, 120, 15);
    });

    expect(useTripStore.getState().fatigueFactor).toBe(0.8);
    expect(useTripStore.getState().maxDistancePerDay).toBe(80);
    expect(useTripTemporalStore.getState().canUndo).toBe(false);
  });
});

describe("useTripPlanner — batch recompute", () => {
  it("marks the stages as they are when the batch lands, not as they were rendered", async () => {
    holder.status = 200;
    useTripStore.setState({
      pendingModifications: [
        { stageId: "stage-2", type: "distance", label: "Day 2" },
      ],
    });
    const { result } = renderHook(() => useTripPlanner());
    const apply = result.current.handleApplyBatch;

    // A day split off stage 2 between the render and the click.
    act(() => {
      useTripStore.setState({
        stages: [stage(1), stage(2), { ...stage(3), id: "split" }, stage(4)],
      });
    });
    await act(async () => {
      await apply();
    });

    expect([...useTripStore.getState().recomputingStages].sort()).toEqual([
      "split",
      "stage-2",
      "stage-4",
    ]);
  });
});

describe("useTripPlanner — a refused structural edit restores the trip's day window", () => {
  it.each([
    [
      "a deleted stage",
      (p: ReturnType<typeof useTripPlanner>) => p.handleDeleteStage(1),
    ],
    [
      "an inserted rest day",
      (p: ReturnType<typeof useTripPlanner>) => p.handleInsertRestDay(0),
    ],
    [
      "an added stage",
      (p: ReturnType<typeof useTripPlanner>) => p.handleAddStage(0),
    ],
  ])("puts the end date back after %s", async (_, edit) => {
    holder.status = 422;
    useTripStore.setState({ startDate: "2026-10-01", endDate: "2026-10-03" });
    const { result } = renderHook(() => useTripPlanner());

    await act(async () => {
      await edit(result.current);
    });

    expect(useTripStore.getState().stages).toHaveLength(3);
    expect(useTripStore.getState().endDate).toBe("2026-10-03");
    expect(useTripTemporalStore.getState().canUndo).toBe(false);
  });
});

describe("useTripPlanner — a refused edit withdraws its own undo entry, not the latest", () => {
  it("keeps the accepted edits undoable, in order, without the refused value", async () => {
    const settle: ((status: number) => void)[] = [];
    holder.deferred = settle;
    useTripStore.setState({
      startDate: "2026-10-01",
      endDate: "2026-10-03",
      fatigueFactor: 0.8,
    });
    const { result } = renderHook(() => useTripPlanner());

    // Three edits in flight at once: the dates, then two pacing commits.
    let dates!: Promise<void>, first!: Promise<void>, second!: Promise<void>;
    act(() => {
      dates = result.current.handleDatesChange("2026-11-01", "2026-11-03");
    });
    act(() => {
      first = result.current.handlePacingCommit(0.9, 100, 80, 15);
    });
    act(() => {
      second = result.current.handlePacingCommit(1, 100, 80, 15);
    });
    // Both pacing commits are accepted, then the dates are refused.
    await act(async () => {
      settle[1]!(200);
      settle[2]!(200);
      await Promise.all([first, second]);
      settle[0]!(422);
      await dates;
    });

    const undo = () => act(() => useTripTemporalStore.getState().undo());
    expect(useTripStore.getState()).toMatchObject({
      startDate: "2026-10-01",
      fatigueFactor: 1,
    });
    undo();
    expect(useTripStore.getState()).toMatchObject({
      startDate: "2026-10-01",
      endDate: "2026-10-03",
      fatigueFactor: 0.9,
    });
    undo();
    expect(useTripStore.getState()).toMatchObject({
      startDate: "2026-10-01",
      fatigueFactor: 0.8,
    });
    expect(useTripTemporalStore.getState().canUndo).toBe(false);
  });
});

describe("useTripPlanner — a refused edit reverts only itself", () => {
  it("keeps a rest day accepted while a refused deletion was in flight", async () => {
    const settle: ((status: number) => void)[] = [];
    holder.deferred = settle;
    useTripStore.setState({ startDate: "2026-10-01", endDate: "2026-10-03" });
    const { result } = renderHook(() => useTripPlanner());

    let deletion!: Promise<void>, restDay!: Promise<void>;
    act(() => {
      deletion = result.current.handleDeleteStage(1);
    });
    act(() => {
      restDay = result.current.handleInsertRestDay(0);
    });
    const restDayId = useTripStore.getState().stages[1]!.id;
    await act(async () => {
      settle[1]!(200);
      await restDay;
      settle[0]!(422);
      await deletion;
    });

    const state = useTripStore.getState();
    expect(state.stages.map((s) => s.id)).toEqual([
      "stage-1",
      restDayId,
      "stage-2",
      "stage-3",
    ]);
    expect(state.endDate).toBe("2026-10-04");
  });

  it("puts back the terrain alerts of a refused e-bike toggle without undoing a stage change made meanwhile", async () => {
    const settle: ((status: number) => void)[] = [];
    holder.deferred = settle;
    const terrain = {
      type: "warning" as const,
      message: "Steep",
      lat: 0,
      lon: 0,
      group: "terrain",
    };
    useTripStore.setState({
      ebikeMode: true,
      stages: [{ ...stage(1), alerts: [terrain] }, stage(2)],
    });
    const { result } = renderHook(() => useTripPlanner());

    let toggle!: Promise<void>;
    act(() => {
      toggle = result.current.handleEbikeModeChange(false);
    });
    // A day lands in front while the toggle is in flight.
    act(() => {
      useTripStore.setState({
        stages: [stage(0), ...useTripStore.getState().stages],
      });
    });
    await act(async () => {
      settle[0]!(422);
      await toggle;
    });

    const state = useTripStore.getState();
    expect(state.ebikeMode).toBe(true);
    expect(state.stages.map((s) => s.id)).toEqual([
      "stage-0",
      "stage-1",
      "stage-2",
    ]);
    expect(state.stages[1]?.alerts).toEqual([terrain]);
  });
});
