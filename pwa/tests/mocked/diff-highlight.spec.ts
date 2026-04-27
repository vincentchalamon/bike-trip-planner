import { test, expect } from "../fixtures/base.fixture";
import type { MercureEvent } from "../../src/lib/mercure/types";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
  accommodationsFoundEvent,
} from "../fixtures/mock-data";

/**
 * Issue #328 — Diff highlight after inline recomputation.
 *
 * After a `stage_updated` event lands, fields that changed on the stage card
 * are briefly highlighted in yellow (3 s fade-out animation). The highlight
 * disappears after the timer expires, and screen-reader users receive an
 * accessible announcement.
 */

/** A stage_updated event that changes the distance (72.5 → 55.0 km). */
function stageUpdatedWithDistanceChange(stageIndex: number): MercureEvent {
  return {
    type: "stage_updated",
    data: {
      stageIndex,
      stage: {
        dayNumber: stageIndex + 1,
        distance: 55.0, // was 72.5 km in stagesComputedEvent
        elevation: 720,
        elevationLoss: 640,
        startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
        endPoint: { lat: 44.5, lon: 4.4, ele: 500 },
        geometry: [
          { lat: 44.735, lon: 4.598, ele: 280 },
          { lat: 44.5, lon: 4.4, ele: 500 },
        ],
        label: null,
        isRestDay: false,
        weather: null,
        alerts: [],
        pois: [],
        accommodations: [],
        selectedAccommodation: null,
        events: [],
      },
    },
  };
}

/** A stage_updated event that adds a new alert on the stage. */
function stageUpdatedWithNewAlerts(stageIndex: number): MercureEvent {
  return {
    type: "stage_updated",
    data: {
      stageIndex,
      stage: {
        dayNumber: stageIndex + 1,
        distance: 72.5, // same distance — no distance diff
        elevation: 1180,
        elevationLoss: 920,
        startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
        endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
        geometry: [
          { lat: 44.735, lon: 4.598, ele: 280 },
          { lat: 44.532, lon: 4.392, ele: 540 },
        ],
        label: null,
        isRestDay: false,
        weather: null,
        alerts: [
          {
            type: "warning",
            message: "Newly detected steep gradient",
            lat: 44.6,
            lon: 4.5,
          },
        ],
        pois: [],
        accommodations: [],
        selectedAccommodation: null,
        events: [],
      },
    },
  };
}

test.describe("Diff highlight after inline recomputation", () => {
  test("distance field is highlighted after stage_updated with changed distance", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Trigger inline recomputation via accommodation selection
    const stageCard = mockedPage.getByTestId("stage-card-1");
    const selectButtons = stageCard.getByRole("button", {
      name: "Sélectionner cet hébergement",
    });
    await selectButtons.first().click();

    // Wait for skeleton to appear (recomputation in progress)
    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });

    // Inject stage_updated with distance change
    await injectEvent(stageUpdatedWithDistanceChange(0));

    // Stage card should be back
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 3000,
    });

    // Distance diff highlight should be present
    await expect(mockedPage.getByTestId("diff-highlight-distance")).toBeVisible(
      { timeout: 1000 },
    );
  });

  test("diff highlight disappears after ~3s", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Trigger recomputation
    const stageCard = mockedPage.getByTestId("stage-card-1");
    const selectButtons = stageCard.getByRole("button", {
      name: "Sélectionner cet hébergement",
    });
    await selectButtons.first().click();
    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });

    // Inject the update
    await injectEvent(stageUpdatedWithDistanceChange(0));
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 3000,
    });

    // Highlight should be visible immediately after the update
    await expect(mockedPage.getByTestId("diff-highlight-distance")).toBeVisible(
      { timeout: 1000 },
    );

    // After ~3.5 seconds the store timer should have fired and removed the
    // highlight (data-testid is only set when isChanged is true).
    await mockedPage.waitForTimeout(3500);
    await expect(
      mockedPage.getByTestId("diff-highlight-distance"),
    ).toBeHidden();
  });

  test("new alerts are highlighted after stage_updated adds alerts", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Trigger inline recomputation via accommodation selection
    const stageCard = mockedPage.getByTestId("stage-card-1");
    const selectButtons = stageCard.getByRole("button", {
      name: "Sélectionner cet hébergement",
    });
    await selectButtons.first().click();

    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });

    // Inject stage_updated that adds a new alert
    await injectEvent(stageUpdatedWithNewAlerts(0));

    // Stage card should be back
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 3000,
    });

    // Alerts-added diff highlight should be present
    await expect(
      mockedPage.getByTestId("diff-highlight-alerts_added"),
    ).toBeVisible({ timeout: 1000 });
  });
});
