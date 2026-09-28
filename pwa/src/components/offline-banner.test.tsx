import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, act } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { OfflineBanner } from "./offline-banner";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

const originalOnLine = Object.getOwnPropertyDescriptor(
  window.navigator,
  "onLine",
);

function setOnLine(value: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    get: () => value,
  });
}

describe("OfflineBanner", () => {
  afterEach(() => {
    if (originalOnLine) {
      Object.defineProperty(window.navigator, "onLine", originalOnLine);
    }
  });

  it("shows the offline message when mounted offline", () => {
    setOnLine(false);
    render(<OfflineBanner />);
    expect(screen.getByText("offlineMessage")).toBeInTheDocument();
  });

  it("announces the reconnection, then hides", () => {
    vi.useFakeTimers();
    setOnLine(false);
    render(<OfflineBanner />);

    act(() => {
      setOnLine(true);
      window.dispatchEvent(new Event("online"));
    });
    expect(screen.getByText("reconnected")).toBeInTheDocument();

    act(() => vi.advanceTimersByTime(3000));
    expect(screen.queryByTestId("offline-banner")).not.toBeInTheDocument();
    vi.useRealTimers();
  });

  it("renders nothing while online", () => {
    setOnLine(true);
    render(<OfflineBanner />);
    expect(screen.queryByTestId("offline-banner")).not.toBeInTheDocument();
  });
});
