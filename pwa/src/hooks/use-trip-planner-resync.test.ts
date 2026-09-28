import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { EMPTY_RESUPPLY, type StageData } from "@btp/core";

// Only the HTTP boundary is faked: parseApiError and the rest of the client keep their real
// behaviour, so the status→"stale" mapping is exercised rather than restated.
const holder = vi.hoisted(() => ({ status: 200, offline: false }));

vi.mock("@/lib/api/client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/api/client")>();
  const respond = async () => {
    if (holder.offline) throw new TypeError("Failed to fetch");

    return {
      data: undefined,
      error: {},
      response: new Response(null, { status: holder.status }),
    };
  };

  return {
    ...actual,
    apiClient: { DELETE: respond, PATCH: respond, POST: respond },
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
