import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  computationErrorEvent,
} from "../fixtures/mock-data";

/**
 * TripSummary — per-block weather state (ADR-043).
 *
 * The weather chip in the trip header surfaces a skeleton while the weather
 * enrichment block is pending/running, driven by `useUiStore.blockStatus.weather`
 * (hydrated from `/detail`, kept live by Mercure). A `computation_error` for
 * `weather` or `wind` flips the block to `failed`, which clears the skeleton.
 */
test.describe("TripSummary — weather block status", () => {
  test("shows the weather skeleton while the weather block is running", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());

    // Structural stages render the trip view.
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Settle the global processing flag so the skeleton can only be driven by
    // `blockStatus.weather` (isolating the per-block path from the legacy
    // `isWeatherLoading` fallback).
    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_processing", { detail: false }),
      );
    });
    await expect(mockedPage.getByTestId("weather-skeleton")).toHaveCount(0);

    // Mark the weather block running → skeleton appears.
    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_block_status", {
          detail: { weather: "running" },
        }),
      );
    });

    await expect(mockedPage.getByTestId("weather-skeleton")).toBeVisible();
  });

  test("clears the weather skeleton when a wind computation_error lands", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());

    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Weather block running → skeleton visible.
    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_block_status", {
          detail: { weather: "running" },
        }),
      );
    });
    await expect(mockedPage.getByTestId("weather-skeleton")).toBeVisible();

    // A `computation_error` for `wind` maps onto the weather block (use-mercure)
    // and flips it to `failed`, which clears the skeleton.
    await injectEvent(computationErrorEvent(false, "wind"));

    await expect(mockedPage.getByTestId("weather-skeleton")).toHaveCount(0);
  });
});

/**
 * TripAiOverview — per-block AI state (ADR-043).
 *
 * The trip-level AI overview card surfaces a skeleton while the LLM enrichment
 * block is pending/running, driven by `useUiStore.blockStatus.ai` (and gated by
 * `aiCapability.configured`). A `computation_error` for `stage_ai_analysis` or
 * `trip_ai_overview` flips the block to `failed`, which swaps the loading card
 * for the error/retry card. Pendant of the weather block tests above.
 */
test.describe("TripAiOverview — ai block status", () => {
  test("swaps the AI loading card for the failed card on a stage_ai_analysis computation_error", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());

    // Structural stages render the trip view.
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // The AI card only renders its per-block states when a provider is
    // configured (the gate in TripAiOverview); drive it via the test hook.
    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_ai_capability", {
          detail: { available: true, configured: true },
        }),
      );
    });

    // Mark the AI block running → loading card appears (no overview yet).
    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_block_status", {
          detail: { ai: "running" },
        }),
      );
    });
    await expect(
      mockedPage.getByTestId("trip-ai-overview-loading"),
    ).toBeVisible();

    // A `computation_error` for `stage_ai_analysis` maps onto the ai block
    // (use-mercure) and flips it to `failed`: loading gone, failed shown.
    await injectEvent(computationErrorEvent(false, "stage_ai_analysis"));

    await expect(
      mockedPage.getByTestId("trip-ai-overview-loading"),
    ).toHaveCount(0);
    await expect(
      mockedPage.getByTestId("trip-ai-overview-failed"),
    ).toBeVisible();
  });
});
