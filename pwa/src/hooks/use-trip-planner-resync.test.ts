import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { EMPTY_RESUPPLY, type StageData } from "@btp/core";

// Only the HTTP boundary is faked: parseApiError and the rest of the client keep their real
// behaviour, so the status→"stale" mapping is exercised rather than restated.
const holder = vi.hoisted(() => ({ status: 200 }));

vi.mock("@/lib/api/client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/api/client")>();
  const respond = async () => ({
    data: undefined,
    error: {},
    response: new Response(null, { status: holder.status }),
  });

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
import { useTripStore } from "@/store/trip-store";
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
