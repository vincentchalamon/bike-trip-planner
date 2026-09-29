import { afterEach, describe, expect, it } from "vitest";
import { takeUrlFragment } from "./url-fragment";

describe("takeUrlFragment", () => {
  afterEach(() => window.history.replaceState(null, "", "/"));

  it("returns the fragment and erases it from the address bar", () => {
    window.history.replaceState(null, "", "/auth/verify#tok-123");

    expect(takeUrlFragment()).toBe("tok-123");
    expect(window.location.hash).toBe("");
    expect(window.location.pathname).toBe("/auth/verify");
  });

  it("keeps the query string", () => {
    window.history.replaceState(null, "", "/page?x=1#secret");

    expect(takeUrlFragment()).toBe("secret");
    expect(window.location.search).toBe("?x=1");
  });

  it("returns an empty string when there is no fragment", () => {
    window.history.replaceState(null, "", "/auth/verify");

    expect(takeUrlFragment()).toBe("");
  });
});
