import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { useGeolocation } from "./use-geolocation";

interface MockGeolocation {
  getCurrentPosition: ReturnType<typeof vi.fn>;
}

const originalGeolocation = Object.getOwnPropertyDescriptor(
  globalThis.navigator,
  "geolocation",
);

function setGeolocation(value: MockGeolocation | undefined): void {
  Object.defineProperty(globalThis.navigator, "geolocation", {
    configurable: true,
    writable: true,
    value,
  });
}

describe("useGeolocation", () => {
  let mockGeolocation: MockGeolocation;

  beforeEach(() => {
    mockGeolocation = { getCurrentPosition: vi.fn() };
    setGeolocation(mockGeolocation);
  });

  afterEach(() => {
    if (originalGeolocation) {
      Object.defineProperty(
        globalThis.navigator,
        "geolocation",
        originalGeolocation,
      );
    } else {
      delete (globalThis.navigator as { geolocation?: unknown }).geolocation;
    }
    vi.restoreAllMocks();
  });

  it("starts with no position, no error and isRequesting=false", () => {
    const { result } = renderHook(() => useGeolocation());
    expect(result.current.position).toBeNull();
    expect(result.current.error).toBeNull();
    expect(result.current.isRequesting).toBe(false);
  });

  it("resolves a position when getCurrentPosition succeeds", () => {
    mockGeolocation.getCurrentPosition.mockImplementation((success) => {
      success({
        coords: { latitude: 48.85, longitude: 2.35, accuracy: 12 },
        timestamp: 1700000000000,
      });
    });

    const { result } = renderHook(() => useGeolocation());
    act(() => {
      result.current.request();
    });

    expect(result.current.position).toEqual({
      latitude: 48.85,
      longitude: 2.35,
      accuracy: 12,
      timestamp: 1700000000000,
    });
    expect(result.current.error).toBeNull();
    expect(result.current.isRequesting).toBe(false);
  });

  it("calls getCurrentPosition with battery-friendly options (no watchPosition)", () => {
    mockGeolocation.getCurrentPosition.mockImplementation(() => {});
    const { result } = renderHook(() => useGeolocation());
    act(() => {
      result.current.request();
    });

    expect(mockGeolocation.getCurrentPosition).toHaveBeenCalledTimes(1);
    const options = mockGeolocation.getCurrentPosition.mock.calls[0]?.[2];
    expect(options).toEqual({
      enableHighAccuracy: false,
      timeout: 10_000,
      maximumAge: 60_000,
    });
  });

  it.each([
    [1, "denied"],
    [2, "unavailable"],
    [3, "timeout"],
  ])("maps PositionError code %i to %s", (code, expected) => {
    mockGeolocation.getCurrentPosition.mockImplementation((_, errorCb) => {
      errorCb({ code, message: `mock ${expected}` });
    });

    const { result } = renderHook(() => useGeolocation());
    act(() => {
      result.current.request();
    });

    expect(result.current.error).toEqual({
      code: expected,
      message: `mock ${expected}`,
    });
    expect(result.current.isRequesting).toBe(false);
  });

  it("reports unsupported when navigator.geolocation is missing", () => {
    setGeolocation(undefined);
    const { result } = renderHook(() => useGeolocation());

    act(() => {
      result.current.request();
    });

    expect(result.current.error?.code).toBe("unsupported");
  });

  it("re-entrancy guard: a double-tap fires getCurrentPosition only once", () => {
    // The first call captures the success callback without invoking it, so the
    // hook is left in flight (isRequesting=true) — the second call must short
    // out via the ref-based guard.
    let capturedSuccess: PositionCallback | undefined;
    mockGeolocation.getCurrentPosition.mockImplementation((successCb) => {
      capturedSuccess = successCb;
    });

    const { result } = renderHook(() => useGeolocation());

    act(() => {
      result.current.request();
      result.current.request();
    });

    expect(mockGeolocation.getCurrentPosition).toHaveBeenCalledTimes(1);

    // Resolve the first request so the guard releases for a follow-up call.
    act(() => {
      capturedSuccess?.({
        coords: {
          latitude: 48.8566,
          longitude: 2.3522,
          accuracy: 12,
          altitude: null,
          altitudeAccuracy: null,
          heading: null,
          speed: null,
        },
        timestamp: Date.now(),
      } as GeolocationPosition);
    });

    act(() => {
      result.current.request();
    });

    expect(mockGeolocation.getCurrentPosition).toHaveBeenCalledTimes(2);
  });
});
