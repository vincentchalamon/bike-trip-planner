import { describe, it, expect, vi, afterEach } from "vitest";
import { render } from "@testing-library/react";
import { PlausibleScript } from "./plausible-script";

vi.mock("next/script", () => ({
  default: (props: Record<string, string>) => (
    <div data-testid="plausible-script" data-src={props.src} />
  ),
}));

type Overridable = Window & {
  __PLAYWRIGHT_PLAUSIBLE_DOMAIN?: string;
  __PLAYWRIGHT_PLAUSIBLE_SRC?: string;
};

function presetOverride(): void {
  const w = window as Overridable;
  w.__PLAYWRIGHT_PLAUSIBLE_DOMAIN = "example.test";
  w.__PLAYWRIGHT_PLAUSIBLE_SRC = "https://plausible.test/js/script.js";
}

describe("PlausibleScript", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
    const w = window as Overridable;
    delete w.__PLAYWRIGHT_PLAUSIBLE_DOMAIN;
    delete w.__PLAYWRIGHT_PLAUSIBLE_SRC;
  });

  it("ignores the window override in a build without test hooks", () => {
    vi.stubEnv("NEXT_PUBLIC_ENABLE_TEST_HOOKS", "false");
    vi.stubEnv("NEXT_PUBLIC_PLAUSIBLE_DOMAIN", "");
    vi.stubEnv("NEXT_PUBLIC_PLAUSIBLE_SRC", "");
    presetOverride();

    const { container } = render(<PlausibleScript />);

    expect(
      container.querySelector("[data-testid=plausible-script]"),
    ).toBeNull();
  });

  it("honours the window override when the test hooks are on", () => {
    vi.stubEnv("NEXT_PUBLIC_ENABLE_TEST_HOOKS", "true");
    vi.stubEnv("NEXT_PUBLIC_PLAUSIBLE_DOMAIN", "");
    vi.stubEnv("NEXT_PUBLIC_PLAUSIBLE_SRC", "");
    presetOverride();

    const { container } = render(<PlausibleScript />);

    expect(
      container
        .querySelector("[data-testid=plausible-script]")
        ?.getAttribute("data-src"),
    ).toBe("https://plausible.test/js/script.js");
  });

  it("loads the configured tracker, whatever the page globals say", () => {
    vi.stubEnv("NEXT_PUBLIC_ENABLE_TEST_HOOKS", "true");
    vi.stubEnv("NEXT_PUBLIC_PLAUSIBLE_DOMAIN", "bike.example");
    vi.stubEnv(
      "NEXT_PUBLIC_PLAUSIBLE_SRC",
      "https://analytics.example/js/script.js",
    );
    presetOverride();

    const { container } = render(<PlausibleScript />);

    expect(
      container
        .querySelector("[data-testid=plausible-script]")
        ?.getAttribute("data-src"),
    ).toBe("https://analytics.example/js/script.js");
  });
});
