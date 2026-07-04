import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MercureClient } from "./client";

const HUB = "https://example.test/.well-known/mercure";

describe("MercureClient test-hook gate (SEC-012)", () => {
  beforeEach(() => {
    // jsdom has no EventSource; stub it so connect() does not throw.
    vi.stubGlobal(
      "EventSource",
      class {
        close() {}
      },
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
    vi.restoreAllMocks();
  });

  it("does not attach the test SSE listener when the flag is off", () => {
    vi.stubEnv("NEXT_PUBLIC_ENABLE_TEST_HOOKS", "false");
    const addSpy = vi.spyOn(window, "addEventListener");

    new MercureClient(HUB, "topic").onEvent(() => {});

    expect(addSpy).not.toHaveBeenCalledWith(
      "__test_mercure_event",
      expect.anything(),
    );
  });

  it("attaches the test SSE listener when the flag is on", () => {
    vi.stubEnv("NEXT_PUBLIC_ENABLE_TEST_HOOKS", "true");
    const addSpy = vi.spyOn(window, "addEventListener");

    new MercureClient(HUB, "topic").onEvent(() => {});

    expect(addSpy).toHaveBeenCalledWith(
      "__test_mercure_event",
      expect.any(Function),
    );
  });
});
