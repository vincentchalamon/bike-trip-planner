import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";
import type { MercureEvent } from "../../src/lib/mercure/types";

function terrainAlertsWithSourceEvent(): MercureEvent {
  return {
    type: "terrain_alerts",
    data: {
      alertsByStage: {
        "0": [
          {
            type: "warning",
            message: "Gravel road for 3km",
            source: "terrain",
            lat: 44.6,
            lon: 4.5,
          },
        ],
      },
    },
  };
}

test.describe("DifficultyGauge", () => {
  test("renders progressbars with correct aria-valuenow for stage 1", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripCompleteEvent(),
    ]);

    // Stage 1: distance=72.5km → score=73, elevation=1180m → score=79
    const card = mockedPage.getByTestId("stage-card-1");
    const bars = card.locator('[role="progressbar"]');
    await expect(bars).toHaveCount(2);
    await expect(bars.nth(0)).toHaveAttribute("aria-valuenow", "73");
    await expect(bars.nth(1)).toHaveAttribute("aria-valuenow", "79");
  });

  test("progressbars have accessible aria-label on the progressbar element", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripCompleteEvent(),
    ]);

    const card = mockedPage.getByTestId("stage-card-1");
    const bars = card.locator('[role="progressbar"]');
    // Labels must be on the element with role="progressbar", not on a wrapper
    await expect(bars.nth(0)).toHaveAttribute("aria-label", /.+/);
    await expect(bars.nth(1)).toHaveAttribute("aria-label", /.+/);
  });

  test("shows surface progressbar when terrain alert with source terrain is present", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      terrainAlertsWithSourceEvent(),
      tripCompleteEvent(),
    ]);

    // Stage 1 has a terrain alert → surface bar should appear (3 bars total)
    const card = mockedPage.getByTestId("stage-card-1");
    await expect(card.locator('[role="progressbar"]')).toHaveCount(3);

    // Stage 2 has no terrain alert → only 2 bars
    const card2 = mockedPage.getByTestId("stage-card-2");
    await expect(card2.locator('[role="progressbar"]')).toHaveCount(2);
  });

  test("surface bar has aria-valuenow of 100 when terrain alert present", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      terrainAlertsWithSourceEvent(),
      tripCompleteEvent(),
    ]);

    const card = mockedPage.getByTestId("stage-card-1");
    const bars = card.locator('[role="progressbar"]');
    await expect(bars.nth(2)).toHaveAttribute("aria-valuenow", "100");
  });
});
