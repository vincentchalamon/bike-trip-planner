import { afterEach, describe, expect, it, vi } from "vitest";
import { trackEvent } from "./plausible";

describe("trackEvent", () => {
  afterEach(() => {
    delete window.plausible;
    vi.restoreAllMocks();
  });

  it("calls window.plausible with the event name and no options when no props", () => {
    const spy = vi.fn();
    window.plausible = spy;

    trackEvent("trip_created");

    expect(spy).toHaveBeenCalledWith("trip_created", undefined);
  });

  it("wraps props under the `props` key", () => {
    const spy = vi.fn();
    window.plausible = spy;

    trackEvent("import_komoot", { source: "import_komoot" });

    expect(spy).toHaveBeenCalledWith("import_komoot", {
      props: { source: "import_komoot" },
    });
  });

  it("is a no-op when window.plausible is undefined (no consent / not loaded)", () => {
    expect(window.plausible).toBeUndefined();
    expect(() => trackEvent("ai_chat_opened")).not.toThrow();
  });
});
