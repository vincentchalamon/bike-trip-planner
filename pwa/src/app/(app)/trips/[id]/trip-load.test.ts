import { describe, it, expect } from "vitest";
import {
  MAX_INITIAL_ATTEMPTS,
  needsResync,
  shouldRetryInitialLoad,
} from "./trip-load";

describe("shouldRetryInitialLoad", () => {
  const res404 = new Response(null, { status: 404 });
  const res500 = new Response(null, { status: 500 });

  it("retries a 404 on our freshly-created trip until attempts run out", () => {
    expect(shouldRetryInitialLoad(res404, 0, true)).toBe(true);
    expect(shouldRetryInitialLoad(res404, MAX_INITIAL_ATTEMPTS - 1, true)).toBe(
      true,
    );
    // Exhausted: the caller surfaces "Voyage introuvable" instead of spinning.
    expect(shouldRetryInitialLoad(res404, MAX_INITIAL_ATTEMPTS, true)).toBe(
      false,
    );
  });

  it("never retries a 404 on a foreign / missing trip (isolation, ADR-038)", () => {
    expect(shouldRetryInitialLoad(res404, 0, false)).toBe(false);
  });

  it("retries a transient network failure regardless of ownership", () => {
    expect(shouldRetryInitialLoad(null, 0, false)).toBe(true);
    expect(shouldRetryInitialLoad(null, MAX_INITIAL_ATTEMPTS, false)).toBe(
      false,
    );
  });

  it("never retries a definitive server error (5xx)", () => {
    expect(shouldRetryInitialLoad(res500, 0, true)).toBe(false);
  });
});

describe("needsResync", () => {
  it("re-syncs a draft trip with no stages", () => {
    expect(needsResync({ status: "draft", stages: [] })).toBe(true);
  });

  it("stops once the trip is ready", () => {
    expect(needsResync({ status: "ready", stages: [] })).toBe(false);
  });

  it("stops as soon as stages are present, even without a status", () => {
    expect(needsResync({ stages: [{}] })).toBe(false);
  });
});
