import { describe, it, expect, vi, afterEach, beforeEach } from "vitest";
import { render, screen, fireEvent, cleanup } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { TripAiOverview } from "./trip-ai-overview";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";

// Echo translation keys so assertions can target them directly.
vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

const OVERVIEW = {
  narrative: "Une belle traversée.",
  patterns: [],
  recommendations: [],
  crossStageAlerts: [],
  model: "gemini-2.5-flash",
  promptVersion: 1,
  generatedAt: "2026-07-22T10:00:00+00:00",
};

describe("TripAiOverview — outdated (stale) banner", () => {
  beforeEach(() => {
    useUiStore.setState({
      aiCapability: { available: true, configured: true },
      blockStatus: { weather: "done", ai: "done" },
    });
    useTripStore.setState({ aiOverview: OVERVIEW, aiOverviewStale: false });
  });

  afterEach(() => {
    cleanup();
    useTripStore.setState({ aiOverview: null, aiOverviewStale: false });
  });

  it("hides the banner when the overview is fresh", () => {
    render(<TripAiOverview onRegenerate={() => {}} />);
    expect(screen.queryByTestId("trip-ai-overview-stale")).not.toBeInTheDocument();
  });

  it("shows the outdated banner + regenerate button when stale", () => {
    useTripStore.setState({ aiOverview: OVERVIEW, aiOverviewStale: true });

    render(<TripAiOverview onRegenerate={() => {}} />);

    expect(screen.getByTestId("trip-ai-overview-stale")).toBeInTheDocument();
    expect(
      screen.getByTestId("trip-ai-overview-stale-regenerate"),
    ).toBeInTheDocument();
  });

  it("calls onRegenerate when the banner button is clicked", () => {
    useTripStore.setState({ aiOverview: OVERVIEW, aiOverviewStale: true });
    const onRegenerate = vi.fn();

    render(<TripAiOverview onRegenerate={onRegenerate} />);
    fireEvent.click(screen.getByTestId("trip-ai-overview-stale-regenerate"));

    expect(onRegenerate).toHaveBeenCalledOnce();
  });
});
