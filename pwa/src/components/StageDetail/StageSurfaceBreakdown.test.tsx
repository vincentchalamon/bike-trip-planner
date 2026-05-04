import { render } from "@testing-library/react";
import { describe, it, expect, vi } from "vitest";
import "@testing-library/jest-dom/vitest";
import {
  StageSurfaceBreakdown,
  aggregateBreakdown,
} from "./StageSurfaceBreakdown";

// Stub next-intl to keep the component decoupled from a NextIntlClientProvider
// in unit tests. Returning the key suffix is enough to assert layout logic.
vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

describe("aggregateBreakdown", () => {
  it("groups raw OSM surfaces into display families and sums lengths", () => {
    const { totals, totalMeters } = aggregateBreakdown([
      { surface: "asphalt", lengthMeters: 6000 },
      { surface: "concrete", lengthMeters: 1000 },
      { surface: "gravel", lengthMeters: 2500 },
      { surface: "fine_gravel", lengthMeters: 500 },
      { surface: "sett", lengthMeters: 1000 },
      { surface: "weird-osm-value", lengthMeters: 500 },
    ]);

    expect(totalMeters).toBe(11500);
    const byFamily = Object.fromEntries(
      totals.map((t) => [t.family, t.meters]),
    );
    expect(byFamily).toEqual({
      paved: 7000,
      gravel: 3000,
      cobblestone: 1000,
      unknown: 500,
    });
  });

  it("ignores zero/negative segments and preserves ordering", () => {
    const { totals } = aggregateBreakdown([
      { surface: "asphalt", lengthMeters: 0 },
      { surface: "gravel", lengthMeters: 1000 },
    ]);
    expect(totals).toHaveLength(1);
    expect(totals[0]?.family).toBe("gravel");
  });
});

describe("StageSurfaceBreakdown", () => {
  it("returns null when total length is zero", () => {
    const { container } = render(
      <StageSurfaceBreakdown
        breakdown={[{ surface: "asphalt", lengthMeters: 0 }]}
      />,
    );
    expect(container.firstChild).toBeNull();
  });

  it("renders one segment per family and percentages sum to 100", () => {
    const { getByTestId, queryByTestId } = render(
      <StageSurfaceBreakdown
        breakdown={[
          { surface: "asphalt", lengthMeters: 6000 },
          { surface: "gravel", lengthMeters: 3000 },
          { surface: "sett", lengthMeters: 1000 },
        ]}
      />,
    );

    const paved = getByTestId("stage-surface-segment-paved");
    const gravel = getByTestId("stage-surface-segment-gravel");
    const cobble = getByTestId("stage-surface-segment-cobblestone");
    expect(queryByTestId("stage-surface-segment-unpaved")).toBeNull();

    const sum =
      Number(paved.getAttribute("data-percent")) +
      Number(gravel.getAttribute("data-percent")) +
      Number(cobble.getAttribute("data-percent"));
    expect(sum).toBe(100);
  });

  it("absorbs rounding error so the bar always sums to 100%", () => {
    // 33.33% / 33.33% / 33.33% — naive rounding gives 33+33+33=99 → dominant slice fixes it.
    const { getByTestId } = render(
      <StageSurfaceBreakdown
        breakdown={[
          { surface: "asphalt", lengthMeters: 1000 },
          { surface: "gravel", lengthMeters: 1000 },
          { surface: "sett", lengthMeters: 1000 },
        ]}
      />,
    );
    const sum =
      Number(getByTestId("stage-surface-segment-paved").dataset.percent) +
      Number(getByTestId("stage-surface-segment-gravel").dataset.percent) +
      Number(getByTestId("stage-surface-segment-cobblestone").dataset.percent);
    expect(sum).toBe(100);
  });
});
