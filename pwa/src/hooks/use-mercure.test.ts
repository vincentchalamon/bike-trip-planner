import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook } from "@testing-library/react";
import { EMPTY_RESUPPLY, type StageData } from "@btp/core";
import type { MercureEvent } from "@btp/core/mercure";

// Capture the client's onEvent callback so the test can drive SSE events.
const holder = vi.hoisted(() => ({
  onEvent: undefined as ((event: MercureEvent) => void) | undefined,
  closed: false,
}));

vi.mock("@/lib/mercure/client", () => ({
  MercureClient: class {
    onEvent(cb: (event: MercureEvent) => void): void {
      holder.onEvent = cb;
    }
    close(): void {
      holder.closed = true;
    }
  },
}));

vi.mock("@/lib/geocode/client", () => ({
  reverseGeocode: vi.fn().mockResolvedValue({ name: "Somewhere" }),
}));

vi.mock("@/components/ui/sonner", () => ({
  toast: { error: vi.fn(), success: vi.fn(), warning: vi.fn() },
}));

import { useMercure } from "./use-mercure";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { reverseGeocode } from "@/lib/geocode/client";
import { toast } from "@/components/ui/sonner";

const A = { lat: 1, lon: 1, ele: 0 };
const B = { lat: 2, lon: 2, ele: 0 };

function stage(overrides: Partial<StageData> = {}): StageData {
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 0,
    elevationLoss: 0,
    startPoint: A,
    endPoint: B,
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

function connect(): (event: MercureEvent) => void {
  renderHook(() => useMercure("t1"));
  expect(holder.onEvent).toBeDefined();
  return holder.onEvent!;
}

beforeEach(() => {
  vi.clearAllMocks();
  holder.onEvent = undefined;
  useTripStore.setState({ stages: [stage()] });
  useUiStore.setState({
    isProcessing: true,
    isAccommodationScanning: true,
    blockStatus: { weather: "running" },
  });
});

describe("useMercure — UI side effects (kept out of core)", () => {
  it("weather_fetched applies weather via the reducer and settles the weather block", () => {
    const dispatch = connect();
    dispatch({
      type: "weather_fetched",
      data: {
        stages: [
          {
            dayNumber: 1,
            weather: {
              icon: "sun",
              description: "Clear",
              tempMin: 8,
              tempMax: 17,
              windSpeed: 12,
              windDirection: "S",
              precipitationProbability: 20,
              humidity: 60,
              comfortIndex: 4,
              relativeWindDirection: "tailwind",
            },
          },
        ],
      },
    });
    expect(useTripStore.getState().stages[0]!.weather).not.toBeNull();
    expect(useUiStore.getState().blockStatus.weather).toBe("done");
  });

  it("accommodations_found settles the accommodation-scanning spinner", () => {
    const dispatch = connect();
    dispatch({
      type: "accommodations_found",
      data: { stageIndex: 0, accommodations: [], searchRadiusKm: 5 },
    });
    expect(useUiStore.getState().isAccommodationScanning).toBe(false);
  });

  it("trip_complete settles processing, scanning and the weather block", () => {
    const dispatch = connect();
    dispatch({ type: "trip_complete", data: { computationStatus: {} } });
    expect(useUiStore.getState().isProcessing).toBe(false);
    expect(useUiStore.getState().isAccommodationScanning).toBe(false);
    expect(useUiStore.getState().blockStatus.weather).toBe("done");
  });

  it("validation_error toasts and clears the processing overlays", () => {
    const dispatch = connect();
    dispatch({
      type: "validation_error",
      data: { code: "x", message: "bad input" },
    });
    expect(toast.error).toHaveBeenCalledWith("bad input");
    expect(useUiStore.getState().isProcessing).toBe(false);
    expect(useUiStore.getState().isAccommodationScanning).toBe(false);
  });

  it("a non-retryable weather computation_error toasts, marks the block failed and stops the spinners", () => {
    const dispatch = connect();
    dispatch({
      type: "computation_error",
      data: { computation: "weather", message: "boom", retryable: false },
    });
    expect(toast.error).toHaveBeenCalled();
    expect(useUiStore.getState().blockStatus.weather).toBe("failed");
    expect(useUiStore.getState().isProcessing).toBe(false);
  });

  it("a retryable computation_error keeps the spinners running", () => {
    const dispatch = connect();
    dispatch({
      type: "computation_error",
      data: { computation: "weather", message: "transient", retryable: true },
    });
    expect(useUiStore.getState().isProcessing).toBe(true);
  });

  it("stages_computed (full replace) geocodes the stages still missing a label", () => {
    const dispatch = connect();
    // The seeded stage has null labels and unmoved endpoints, so the full replace
    // preserves the (null) labels → the stage still needs geocoding.
    dispatch({
      type: "stages_computed",
      data: {
        stages: [
          {
            dayNumber: 1,
            distance: 50,
            elevation: 0,
            elevationLoss: 0,
            startPoint: A,
            endPoint: B,
            geometry: [],
            label: null,
          },
        ],
      },
    });
    expect(reverseGeocode).toHaveBeenCalled();
  });
});
