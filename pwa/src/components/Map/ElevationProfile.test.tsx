import { describe, it, expect, vi } from "vitest";
import { render } from "@testing-library/react";
import { EMPTY_RESUPPLY, type StageData } from "@btp/core";

vi.mock("next-intl", () => ({ useTranslations: () => (key: string) => key }));

import { ElevationProfile } from "./ElevationProfile";

function stage(geometry: StageData["geometry"]): StageData {
  const point = { lat: 0, lon: 0, ele: 0 };

  return {
    id: "stage-1",
    dayNumber: 1,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: point,
    endPoint: point,
    geometry,
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    resupply: EMPTY_RESUPPLY,
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 10,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
  };
}

describe("ElevationProfile", () => {
  // `Math.min(...points)` throws RangeError past the engine's argument limit.
  it("renders a whole-trip profile too long to spread into Math.min/max", () => {
    const geometry = Array.from({ length: 200_000 }, (_, i) => ({
      lat: 45 + i * 1e-6,
      lon: 4,
      ele: 100 + (i % 500),
    }));

    const { container } = render(
      <ElevationProfile
        stages={[stage(geometry)]}
        focusedStageIndex={null}
        onHover={() => {}}
      />,
    );

    expect(container.querySelector("svg")).not.toBeNull();
  });
});
