import { useState } from "react";
import { beforeAll, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { EMPTY_RESUPPLY, type StageData } from "@btp/core";
import { StageDetailPanel } from "./StageDetailPanel";

vi.mock("next-intl", () => ({
  useLocale: () => "en",
  useTranslations: () => (key: string) => key,
}));

// Stands in for the real card: it remembers the stage it was first mounted for,
// the way AlertList remembers which alerts were dismissed.
vi.mock("@/components/stage-card", () => ({
  StageCard: ({ stage }: { stage: StageData }) => {
    const [mountedFor] = useState(stage.id);
    return (
      <div data-testid="card" data-mounted-for={mountedFor}>
        {stage.id}
      </div>
    );
  },
}));

beforeAll(() => {
  vi.stubGlobal(
    "IntersectionObserver",
    class {
      observe() {}
      disconnect() {}
    },
  );
});

function stage(id: string, dayNumber: number): StageData {
  const point = { lat: dayNumber, lon: dayNumber, ele: 0 };
  return {
    id,
    dayNumber,
    distance: 50,
    elevation: 0,
    elevationLoss: 0,
    startPoint: point,
    endPoint: point,
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
  };
}

function panel(stages: StageData[]) {
  return (
    <StageDetailPanel
      stages={stages}
      selectedIndex={0}
      startDate={null}
      readOnly
    />
  );
}

describe("StageDetailPanel stage identity", () => {
  // Keyed by position, a move handed each card's state (dismissed alerts) to the
  // stage that took its place.
  it("keeps each card's state with its stage when the stages are reordered", () => {
    const { rerender } = render(panel([stage("a", 1), stage("b", 2)]));
    rerender(panel([stage("b", 1), stage("a", 2)]));

    const cards = screen.getAllByTestId("card");
    expect(cards).toHaveLength(2);
    for (const card of cards) {
      expect(card.dataset.mountedFor).toBe(card.textContent);
    }
  });
});
