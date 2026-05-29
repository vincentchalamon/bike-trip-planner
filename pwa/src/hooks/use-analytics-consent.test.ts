import { describe, it, expect, beforeAll, afterEach } from "vitest";
import { act, renderHook } from "@testing-library/react";
import {
  CONSENT_CHANGE_EVENT,
  useAnalyticsConsent,
} from "./use-analytics-consent";

beforeAll(() => {
  // jsdom in this setup does not provide a functional localStorage; supply a
  // minimal in-memory implementation.
  const store = new Map<string, string>();
  Object.defineProperty(window, "localStorage", {
    configurable: true,
    value: {
      getItem: (k: string) => store.get(k) ?? null,
      setItem: (k: string, v: string) => store.set(k, v),
      removeItem: (k: string) => store.delete(k),
      clear: () => store.clear(),
    },
  });
});

describe("useAnalyticsConsent", () => {
  afterEach(() => {
    localStorage.clear();
  });

  it("defaults to false when no consent is stored", () => {
    const { result } = renderHook(() => useAnalyticsConsent());
    expect(result.current).toBe(false);
  });

  it("defaults to false when analytics is explicitly refused", () => {
    localStorage.setItem(
      "cookie-consent",
      JSON.stringify({ analytics: false }),
    );
    const { result } = renderHook(() => useAnalyticsConsent());
    expect(result.current).toBe(false);
  });

  it("returns true when analytics consent is granted", () => {
    localStorage.setItem("cookie-consent", JSON.stringify({ analytics: true }));
    const { result } = renderHook(() => useAnalyticsConsent());
    expect(result.current).toBe(true);
  });

  it("returns false on malformed stored value", () => {
    localStorage.setItem("cookie-consent", "not-json");
    const { result } = renderHook(() => useAnalyticsConsent());
    expect(result.current).toBe(false);
  });

  it("reacts to a consent-change event without a reload", () => {
    const { result } = renderHook(() => useAnalyticsConsent());
    expect(result.current).toBe(false);

    act(() => {
      localStorage.setItem(
        "cookie-consent",
        JSON.stringify({ analytics: true }),
      );
      window.dispatchEvent(new Event(CONSENT_CHANGE_EVENT));
    });

    expect(result.current).toBe(true);
  });
});
