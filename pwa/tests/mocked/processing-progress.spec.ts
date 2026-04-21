import { test, expect } from "../fixtures/base.fixture";
import type { Page } from "@playwright/test";
import {
  computationStepCompletedEvent,
  routeParsedEvent,
  stagesComputedEvent,
  tripReadyEvent,
} from "../fixtures/mock-data";
import type { MercureEvent } from "../../src/lib/mercure/types";

/**
 * Issue #323 — Acte 2 ProcessingProgress screen.
 *
 * The progress screen activates when the user has launched the Phase 2
 * analysis (`hasAnalysisStarted`) and the backend is still crunching
 * (`isProcessing`). Categories transition between pending, in_progress,
 * done and failed states as `computation_step_completed` /
 * `computation_error` events arrive. A `trip_ready` event flips
 * `isProcessing` off and hands over to Acte 3 (the full trip view).
 */
async function enterAnalysingState(
  submitUrl: () => Promise<void>,
  injectEvent: (event: MercureEvent) => Promise<void>,
  mockedPage: Page,
): Promise<void> {
  await submitUrl();
  await injectEvent(routeParsedEvent());
  await injectEvent(stagesComputedEvent());
  // Simulate the "user clicked Launch analysis" gate: processing remains
  // true and analysis has been started explicitly.
  await mockedPage.evaluate(() => {
    window.dispatchEvent(
      new CustomEvent("__test_set_processing", { detail: true }),
    );
    window.dispatchEvent(
      new CustomEvent("__test_set_analysis_started", { detail: true }),
    );
  });
  await expect(mockedPage.getByTestId("processing-progress")).toBeVisible({
    timeout: 5000,
  });
}

test.describe("ProcessingProgress — display", () => {
  test("renders the six narrative categories during analysis", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterAnalysingState(submitUrl, injectEvent, mockedPage);

    await expect(
      mockedPage.getByTestId("processing-category-terrain_security"),
    ).toBeVisible();
    await expect(mockedPage.getByTestId("processing-category-supply")).toBeVisible();
    await expect(
      mockedPage.getByTestId("processing-category-accommodations"),
    ).toBeVisible();
    await expect(mockedPage.getByTestId("processing-category-weather")).toBeVisible();
    await expect(
      mockedPage.getByTestId("processing-category-services"),
    ).toBeVisible();
  });

  test("AI category is hidden when Ollama is disabled (no ai_* events)", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterAnalysingState(submitUrl, injectEvent, mockedPage);
    await expect(
      mockedPage.getByTestId("processing-category-ai"),
    ).toBeHidden();
  });

  test("AI category becomes visible when an ai_* step is reported", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterAnalysingState(submitUrl, injectEvent, mockedPage);
    // The AI category is hidden until at least one ai_* step fires.
    await injectEvent(computationStepCompletedEvent("ai_stage", "route", 1, 16));
    await expect(
      mockedPage.getByTestId("processing-category-ai"),
    ).toBeVisible();
  });
});

test.describe("ProcessingProgress — category progression", () => {
  test("starts every category as pending", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterAnalysingState(submitUrl, injectEvent, mockedPage);

    await expect(
      mockedPage.getByTestId("processing-category-terrain_security"),
    ).toHaveAttribute("data-status", "pending");
    await expect(
      mockedPage.getByTestId("processing-category-accommodations"),
    ).toHaveAttribute("data-status", "pending");
  });

  test("moves a category to in_progress while its steps are running", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterAnalysingState(submitUrl, injectEvent, mockedPage);
    // Accommodations has a single step — an in-flight computation_step_completed
    // for "accommodations" would actually flip it to done, so we simulate the
    // currently-running step via the "terrain_security" row (osm_scan + terrain):
    // sending only osm_scan leaves terrain undone → the row is in_progress.
    await injectEvent(
      computationStepCompletedEvent("osm_scan", "points_of_interest", 1, 16),
    );
    await expect(
      mockedPage.getByTestId("processing-category-terrain_security"),
    ).toHaveAttribute("data-status", "in_progress");
  });

  test("marks a category as done once all its steps have completed", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterAnalysingState(submitUrl, injectEvent, mockedPage);
    // Accommodations has a single backing step.
    await injectEvent(
      computationStepCompletedEvent("accommodations", "accommodations", 1, 16),
    );
    await expect(
      mockedPage.getByTestId("processing-category-accommodations"),
    ).toHaveAttribute("data-status", "done");
  });
});

test.describe("ProcessingProgress — failure handling", () => {
  test("renders the warning icon and explanatory message on computation_error", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterAnalysingState(submitUrl, injectEvent, mockedPage);

    await injectEvent({
      type: "computation_error",
      data: {
        computation: "accommodations",
        message: "Overpass timed out",
        retryable: true,
      },
    });

    await expect(
      mockedPage.getByTestId("processing-category-accommodations"),
    ).toHaveAttribute("data-status", "failed");
    await expect(
      mockedPage.getByTestId("processing-category-accommodations-error"),
    ).toContainText(/Overpass timed out/);
  });
});

test.describe("ProcessingProgress — global progress bar", () => {
  test("reflects the completed/total ratio from computation_step_completed", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterAnalysingState(submitUrl, injectEvent, mockedPage);
    await injectEvent(
      computationStepCompletedEvent("terrain", "terrain_security", 4, 16),
    );
    await expect(
      mockedPage.getByTestId("processing-progress-percent"),
    ).toContainText("25%");
  });
});

test.describe("ProcessingProgress — transition to Acte 3", () => {
  test("trip_ready hands over to the full trip view (Acte 3)", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterAnalysingState(submitUrl, injectEvent, mockedPage);
    await injectEvent(tripReadyEvent());
    // The progress screen is gone…
    await expect(mockedPage.getByTestId("processing-progress")).toBeHidden({
      timeout: 5000,
    });
    // …and the regular trip view (stage cards) is now on screen.
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
  });
});
