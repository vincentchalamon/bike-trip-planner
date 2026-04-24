import { test, expect } from "../fixtures/base.fixture";
import {
  computationStepCompletedEvent,
  routeParsedEvent,
  stagesComputedEvent,
  stageUpdatedEvent,
  tripReadyEvent,
} from "../fixtures/mock-data";

/**
 * Issue #324 — Mercure events dual mode.
 *
 * Mode 1 (Acte 2 / initial analysis):
 *   `computation_step_completed` ticks drive the progress bar, then a single
 *   `trip_ready` event carries the full enriched payload so the frontend can
 *   swap state atomically (no cumulative layout shift).
 *
 * Mode 2 (Acte 3 / inline modification):
 *   `stage_updated` mutates a single stage slice without rebuilding the whole
 *   trip, and does not re-trigger the AI overview pass.
 */

test.describe("Mercure dual mode — Mode 1 (initial analysis)", () => {
  test("computation_step_completed updates analysisProgress in the UI store", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent()); // seeds stages for Phase 1
    await injectEvent(
      computationStepCompletedEvent("terrain", "terrain_security", 3, 9),
    );

    // The progress payload landed in the UI store.
    const progress = await mockedPage.evaluate(
      () =>
        (
          window as unknown as {
            __zustand_ui_store: {
              getState: () => {
                analysisProgress: {
                  step: string;
                  category: string;
                  completed: number;
                  total: number;
                } | null;
              };
            };
          }
        ).__zustand_ui_store.getState().analysisProgress,
    );
    expect(progress).toEqual({
      step: "terrain",
      category: "terrain_security",
      completed: 3,
      total: 9,
    });
  });

  test("trip_ready performs an atomic swap and clears analysisProgress", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(computationStepCompletedEvent("route", "route", 1, 9));
    await injectEvent(
      computationStepCompletedEvent("terrain", "terrain_security", 9, 9),
    );
    await injectEvent(tripReadyEvent());

    // Progress is reset once the final payload lands.
    const progress = await mockedPage.evaluate(
      () =>
        (
          window as unknown as {
            __zustand_ui_store: {
              getState: () => { analysisProgress: unknown };
            };
          }
        ).__zustand_ui_store.getState().analysisProgress,
    );
    expect(progress).toBeNull();

    // Exactly 2 stages (tripReadyEvent fixture) — verifies the atomic swap
    // replaced any prior stage state rather than merging.
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeHidden();
  });
});

test.describe("Mercure dual mode — Mode 2 (inline modification)", () => {
  test("stage_updated mutates a single slice without rebuilding the trip", async ({
    createFullTrip,
    injectEvent,
    mockedPage,
  }) => {
    await createFullTrip();

    // Baseline: all 3 stages from the full-trip fixture are present.
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();

    await injectEvent(stageUpdatedEvent(0));

    // The other stages must still be there — no wholesale rebuild.
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();

    // The targeted stage was replaced with the updated distance/elevation.
    // Asserting via the rendered card text keeps the test independent of
    // internal store exposure.
    await expect(mockedPage.getByTestId("stage-card-1")).toContainText(/55/);
  });
});
