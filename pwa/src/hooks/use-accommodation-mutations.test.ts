import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import {
  EMPTY_RESUPPLY,
  type AccommodationData,
  type StageData,
} from "@btp/core";

// The HTTP boundary only: the store, the UI store and parseApiError stay real.
const api = vi.hoisted(() => ({
  patchStatus: 200,
  offline: false,
  scan: vi.fn<(...args: unknown[]) => Promise<boolean>>(),
  addManual:
    vi.fn<(...args: unknown[]) => Promise<{ ok: boolean; status: number }>>(),
}));

vi.mock("@/lib/api/client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/api/client")>();

  return {
    ...actual,
    apiClient: {
      PATCH: async () => {
        if (api.offline) throw new TypeError("Failed to fetch");
        const ok = api.patchStatus < 400;

        return {
          data: undefined,
          error: ok ? undefined : {},
          response: new Response(null, { status: api.patchStatus }),
        };
      },
    },
    scanAccommodations: api.scan,
    addManualAccommodation: api.addManual,
  };
});

vi.mock("next-intl", () => ({ useTranslations: () => (key: string) => key }));
vi.mock("@/lib/plausible", () => ({ trackEvent: vi.fn() }));
vi.mock("@/components/ui/sonner", () => ({
  toast: { error: vi.fn(), success: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

import { useAccommodationMutations } from "./use-accommodation-mutations";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { toast } from "@/components/ui/sonner";

function accommodation(name: string, lat: number): AccommodationData {
  return {
    name,
    type: "hotel",
    lat,
    lon: lat,
    estimatedPriceMin: 50,
    estimatedPriceMax: 80,
    isExactPrice: false,
    possibleClosed: false,
    distanceToEndPoint: 1,
    source: "osm",
  };
}

function stage(
  dayNumber: number,
  overrides: Partial<StageData> = {},
): StageData {
  const point = { lat: dayNumber, lon: dayNumber, ele: 0 };

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
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

const gite = accommodation("Gite", 10);
const hotel = accommodation("Hotel", 11);
const input = { name: "Chez moi", address: "1 rue", priceTotal: 40, url: null };

function hook() {
  return renderHook(() => useAccommodationMutations()).result;
}

function flags() {
  const { isProcessing, isAccommodationScanning } = useUiStore.getState();

  return { isProcessing, isAccommodationScanning };
}

beforeEach(() => {
  vi.clearAllMocks();
  api.patchStatus = 200;
  api.offline = false;
  api.scan.mockResolvedValue(true);
  api.addManual.mockResolvedValue({ ok: true, status: 202 });
  useUiStore.setState({ isProcessing: false, isAccommodationScanning: false });
  useTripStore.getState().clearTrip();
  useTripStore.setState({
    trip: { id: "t1", title: "Trip", sourceUrl: "" },
    outOfZone: false,
    stages: [stage(1, { accommodations: [gite, hotel] }), stage(2), stage(3)],
  });
});

describe("handleExpandAccommodationRadius", () => {
  it("scans the stage at the next radius step and shows the scan spinner", async () => {
    const result = hook();
    let ok: boolean | undefined;
    await act(async () => {
      ok = await result.current.handleExpandAccommodationRadius(0, 5);
    });

    expect(ok).toBe(true);
    expect(api.scan).toHaveBeenCalledWith("t1", 7, "stage-1");
    expect(flags()).toEqual({
      isProcessing: true,
      isAccommodationScanning: true,
    });
  });

  it("refuses to go past the maximum radius without calling the API", async () => {
    const result = hook();
    let ok: boolean | undefined;
    await act(async () => {
      ok = await result.current.handleExpandAccommodationRadius(0, 14);
    });

    expect(ok).toBe(false);
    expect(api.scan).not.toHaveBeenCalled();
  });

  it.each([
    ["refused", () => api.scan.mockResolvedValue(false)],
    ["unreachable", () => api.scan.mockRejectedValue(new TypeError("Failed"))],
  ])("leaves the trip and the spinners alone when %s", async (_, fail) => {
    fail();
    const before = useTripStore.getState().stages;
    const result = hook();
    let ok: boolean | undefined;
    await act(async () => {
      ok = await result.current.handleExpandAccommodationRadius(0, 5);
    });

    expect(ok).toBe(false);
    expect(toast.error).toHaveBeenCalledWith("errors.unexpectedError");
    expect(useTripStore.getState().stages).toBe(before);
    expect(flags()).toEqual({
      isProcessing: false,
      isAccommodationScanning: false,
    });
  });
});

describe("handleAddManualAccommodation", () => {
  it("adds it, then marks the stage and the next one as recomputing", async () => {
    const result = hook();
    let ok: boolean | undefined;
    await act(async () => {
      ok = await result.current.handleAddManualAccommodation(1, input);
    });

    expect(ok).toBe(true);
    expect(api.addManual).toHaveBeenCalledWith("t1", "stage-2", input);
    expect([...useTripStore.getState().recomputingStages]).toEqual([
      "stage-2",
      "stage-3",
    ]);
    expect(useUiStore.getState().isProcessing).toBe(true);
  });

  it("is blocked out of zone", async () => {
    useTripStore.setState({ outOfZone: true });
    const result = hook();
    let ok: boolean | undefined;
    await act(async () => {
      ok = await result.current.handleAddManualAccommodation(1, input);
    });

    expect(ok).toBe(false);
    expect(api.addManual).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledWith("outOfZone.editDisabled");
  });

  it.each([
    [
      "an address that cannot be geocoded",
      () => api.addManual.mockResolvedValue({ ok: false, status: 422 }),
      "errors.accommodationGeocodeFailed",
    ],
    [
      "any other refusal",
      () => api.addManual.mockResolvedValue({ ok: false, status: 500 }),
      "errors.unexpectedError",
    ],
    [
      "a network failure",
      () => api.addManual.mockRejectedValue(new TypeError("Failed")),
      "errors.unexpectedError",
    ],
  ])("changes nothing on %s", async (_, fail, message) => {
    fail();
    const before = useTripStore.getState().stages;
    const result = hook();
    let ok: boolean | undefined;
    await act(async () => {
      ok = await result.current.handleAddManualAccommodation(1, input);
    });

    expect(ok).toBe(false);
    expect(toast.error).toHaveBeenCalledWith(message);
    expect(useTripStore.getState().stages).toBe(before);
    expect(useTripStore.getState().recomputingStages.size).toBe(0);
    expect(useUiStore.getState().isProcessing).toBe(false);
  });
});

describe("handleSelectAccommodation", () => {
  it("keeps the selected one, moves the stage end and the next start, and marks both", async () => {
    const result = hook();
    await act(async () => {
      await result.current.handleSelectAccommodation(0, 1);
    });

    const [first, second] = useTripStore.getState().stages;
    expect(first?.accommodations).toEqual([hotel]);
    expect(first?.selectedAccommodation).toEqual(hotel);
    expect(first?.endPoint).toEqual({ lat: 11, lon: 11, ele: 0 });
    expect(second?.startPoint).toEqual({ lat: 11, lon: 11, ele: 0 });
    expect([...useTripStore.getState().recomputingStages]).toEqual([
      "stage-1",
      "stage-2",
    ]);
  });

  it.each([
    ["refused", () => (api.patchStatus = 422)],
    ["unreachable", () => (api.offline = true)],
  ])("puts the stages back as they were when %s", async (_, fail) => {
    fail();
    const before = useTripStore.getState().stages;
    const result = hook();
    await act(async () => {
      await result.current.handleSelectAccommodation(0, 1);
    });

    expect(useTripStore.getState().stages).toEqual(before);
    expect(useTripStore.getState().recomputingStages.size).toBe(0);
    expect(api.scan).not.toHaveBeenCalled();
  });

  it("on a 409, puts the stages back and rescans that stage at the default radius", async () => {
    api.patchStatus = 409;
    const before = useTripStore.getState().stages;
    const result = hook();
    await act(async () => {
      await result.current.handleSelectAccommodation(0, 1);
    });

    expect(useTripStore.getState().stages).toEqual(before);
    expect(toast.info).toHaveBeenCalledWith("errors.accommodationStale");
    expect(api.scan).toHaveBeenCalledWith("t1", 5, "stage-1");
    expect(useUiStore.getState().isAccommodationScanning).toBe(true);
  });

  it("on a 409 whose rescan is refused too, says so and shows no scan spinner", async () => {
    api.patchStatus = 409;
    api.scan.mockResolvedValue(false);
    const result = hook();
    await act(async () => {
      await result.current.handleSelectAccommodation(0, 1);
    });

    expect(toast.error).toHaveBeenCalledWith("errors.unexpectedError");
    expect(useUiStore.getState().isAccommodationScanning).toBe(false);
  });
});

describe("handleDeselectAccommodation", () => {
  beforeEach(() => {
    useTripStore.setState({
      stages: [
        stage(1, { accommodations: [hotel], selectedAccommodation: hotel }),
        stage(2),
        stage(3),
      ],
    });
  });

  it("clears the selection and marks the stage and the next one", async () => {
    const result = hook();
    await act(async () => {
      await result.current.handleDeselectAccommodation(0);
    });

    expect(useTripStore.getState().stages[0]?.selectedAccommodation).toBeNull();
    expect([...useTripStore.getState().recomputingStages]).toEqual([
      "stage-1",
      "stage-2",
    ]);
    expect(flags()).toEqual({
      isProcessing: true,
      isAccommodationScanning: true,
    });
  });

  it.each([
    ["refused", () => (api.patchStatus = 422)],
    ["unreachable", () => (api.offline = true)],
  ])("restores the selection when %s", async (_, fail) => {
    fail();
    const result = hook();
    await act(async () => {
      await result.current.handleDeselectAccommodation(0);
    });

    expect(useTripStore.getState().stages[0]?.selectedAccommodation).toEqual(
      hotel,
    );
    expect(useTripStore.getState().recomputingStages.size).toBe(0);
    expect(flags()).toEqual({
      isProcessing: false,
      isAccommodationScanning: false,
    });
  });
});
