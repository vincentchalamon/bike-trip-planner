/**
 * Issue #325 — Acte 3 : collapsible stage alerts with severity sorting and pagination.
 *
 * Tests:
 * - Alerts are sorted by severity (critical → warning → nudge).
 * - First 3 alerts shown, "Show N more" reveals the rest.
 * - Section collapses and expands via the header toggle.
 * - Individual alert actions (auto_fix, navigate, dismiss) work as expected.
 * - Transition from Acte 2 ProcessingProgress (trip_ready) to Acte 3.
 */

import { test, expect } from "../fixtures/base.fixture";
import type { MercureEvent } from "../../src/lib/mercure/types";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripReadyEvent,
} from "../fixtures/mock-data";

/** A trip_ready event with many alerts on stage 0 (7 alerts, mixed severity). */
function tripReadyWithManyAlertsEvent(): MercureEvent {
  return {
    type: "trip_ready",
    data: {
      stages: [
        {
          dayNumber: 1,
          distance: 72.5,
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
            // nudge first in list — should appear last after sorting
            {
              type: "nudge",
              message: "Nudge alert A",
              lat: null,
              lon: null,
              action: { kind: "dismiss", label: "OK", payload: {} },
            },
            {
              type: "warning",
              message: "Warning alert B",
              lat: 44.6,
              lon: 4.5,
              action: {
                kind: "navigate",
                label: "Zoom to location",
                payload: { lat: 44.6, lon: 4.5 },
              },
            },
            {
              type: "critical",
              message: "Critical alert C",
              lat: null,
              lon: null,
              action: {
                kind: "auto_fix",
                label: "Split stage",
                payload: { splitAt: 45.0 },
              },
            },
            {
              type: "warning",
              message: "Warning alert D",
              lat: null,
              lon: null,
              action: { kind: "detour", label: "Take detour", payload: {} },
            },
            {
              type: "nudge",
              message: "Nudge alert E",
              lat: null,
              lon: null,
            },
            {
              type: "critical",
              message: "Critical alert F",
              lat: null,
              lon: null,
            },
            {
              type: "warning",
              message: "Warning alert G",
              lat: null,
              lon: null,
            },
          ],
          pois: [],
          accommodations: [],
          selectedAccommodation: null,
          events: [],
        },
      ],
      computationStatus: {
        route: "done",
        stages: "done",
        weather: "done",
        terrain: "done",
        accommodations: "done",
      },
      aiOverview: null,
    },
  };
}

/** A trip_ready event with exactly 3 alerts on stage 0 (no "Show more" needed). */
function tripReadyWithThreeAlertsEvent(): MercureEvent {
  return {
    type: "trip_ready",
    data: {
      stages: [
        {
          dayNumber: 1,
          distance: 72.5,
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
              type: "critical",
              message: "Critical alert 1",
              lat: null,
              lon: null,
            },
            {
              type: "warning",
              message: "Warning alert 2",
              lat: null,
              lon: null,
            },
            {
              type: "nudge",
              message: "Nudge alert 3",
              lat: null,
              lon: null,
              action: { kind: "dismiss", label: "Got it", payload: {} },
            },
          ],
          pois: [],
          accommodations: [],
          selectedAccommodation: null,
          events: [],
        },
      ],
      computationStatus: { route: "done", stages: "done" },
      aiOverview: null,
    },
  };
}

// ---------------------------------------------------------------------------
// Severity sorting
// ---------------------------------------------------------------------------

test.describe("StageAlerts — severity sorting", () => {
  test("alerts are displayed in severity order: critical → warning → nudge", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithManyAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts")).toBeVisible({
      timeout: 5000,
    });

    // After sorting: criticals fill the first visible slots (only 3 shown by default).
    // Both criticals should be visible; nudges should be hidden behind "Show more".
    const alertsBody = stageCard.getByTestId("stage-alerts-body");
    await expect(alertsBody.getByText("Critical alert C")).toBeVisible();
    await expect(alertsBody.getByText("Critical alert F")).toBeVisible();
    // Nudge alerts sit below the fold — not visible until "Show more" is clicked.
    await expect(alertsBody.getByText("Nudge alert A")).not.toBeVisible();
    await expect(alertsBody.getByText("Nudge alert E")).not.toBeVisible();
  });

  test("shows correct total count in the section header", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithManyAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    // 7 alerts total
    await expect(stageCard.getByTestId("stage-alerts-count")).toContainText(
      "7",
    );
  });
});

// ---------------------------------------------------------------------------
// Pagination — "Show N more" / "Show less"
// ---------------------------------------------------------------------------

test.describe("StageAlerts — pagination", () => {
  test("shows only the first 3 alerts by default when there are more than 3", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithManyAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts")).toBeVisible({
      timeout: 5000,
    });

    // The "show more" button should be present (7 alerts − 3 visible = 4 hidden)
    await expect(stageCard.getByTestId("stage-alerts-show-more")).toBeVisible();
    await expect(stageCard.getByTestId("stage-alerts-show-more")).toContainText(
      "4",
    );
  });

  test("reveals all alerts after clicking 'Show more'", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithManyAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts")).toBeVisible({
      timeout: 5000,
    });

    // Click "Show more"
    await stageCard.getByTestId("stage-alerts-show-more").click();

    // All 7 alerts should be visible now — the "Nudge alert A" message (which was
    // below the initial 3) should now be present in the DOM.
    await expect(stageCard).toContainText("Nudge alert A");
    await expect(stageCard).toContainText("Nudge alert E");

    // The toggle label should change to "Show less"
    await expect(
      stageCard.getByTestId("stage-alerts-show-more"),
    ).not.toContainText("4");
  });

  test("does not show 'Show more' when alerts count is at most 3", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithThreeAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts")).toBeVisible({
      timeout: 5000,
    });

    await expect(
      stageCard.getByTestId("stage-alerts-show-more"),
    ).not.toBeVisible();
  });
});

// ---------------------------------------------------------------------------
// Collapse / expand
// ---------------------------------------------------------------------------

test.describe("StageAlerts — collapse/expand", () => {
  test("section is expanded by default", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithThreeAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts-body")).toBeVisible({
      timeout: 5000,
    });
    await expect(stageCard.getByTestId("stage-alerts-toggle")).toHaveAttribute(
      "aria-expanded",
      "true",
    );
  });

  test("clicking the toggle hides the alert body", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithThreeAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts-body")).toBeVisible({
      timeout: 5000,
    });

    // Collapse
    await stageCard.getByTestId("stage-alerts-toggle").click();

    await expect(stageCard.getByTestId("stage-alerts-body")).not.toBeVisible();
    await expect(stageCard.getByTestId("stage-alerts-toggle")).toHaveAttribute(
      "aria-expanded",
      "false",
    );
  });

  test("clicking the toggle again re-expands the section", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithThreeAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts-body")).toBeVisible({
      timeout: 5000,
    });

    // Collapse then expand
    await stageCard.getByTestId("stage-alerts-toggle").click();
    await stageCard.getByTestId("stage-alerts-toggle").click();

    await expect(stageCard.getByTestId("stage-alerts-body")).toBeVisible();
  });
});

// ---------------------------------------------------------------------------
// Alert actions (auto_fix, navigate, dismiss) — inherited from AlertList
// ---------------------------------------------------------------------------

test.describe("StageAlerts — alert actions", () => {
  test("dismiss action marks the alert as dismissed", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithManyAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts")).toBeVisible({
      timeout: 5000,
    });

    // "OK" is the dismiss action on "Nudge alert A" — expand all first
    await stageCard.getByTestId("stage-alerts-show-more").click();

    // Click the dismiss button on "Nudge alert A"
    await stageCard.getByText("OK").first().click();

    // Dismissed alert should show reduced opacity marker
    await expect(stageCard.getByTestId("alert-dismissed")).toBeVisible();
  });

  test("navigate action button is enabled and visible", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithManyAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts")).toBeVisible({
      timeout: 5000,
    });

    // "Zoom to location" is the navigate action on "Warning alert B"
    await expect(stageCard.getByText("Zoom to location")).toBeVisible();
    await expect(stageCard.getByText("Zoom to location")).not.toBeDisabled();
  });

  test("auto_fix action button is disabled (not yet implemented)", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithManyAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts")).toBeVisible({
      timeout: 5000,
    });

    // "Split stage" is the auto_fix action on "Critical alert C"
    await expect(stageCard.getByText("Split stage")).toBeVisible();
    await expect(stageCard.getByText("Split stage")).toBeDisabled();
  });

  test("detour action button is disabled (not yet implemented)", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithManyAlertsEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts")).toBeVisible({
      timeout: 5000,
    });

    // "Warning alert D" (detour) is 4th after severity sort — expand pagination first
    await stageCard.getByTestId("stage-alerts-show-more").click();

    // "Take detour" is the detour action on "Warning alert D"
    await expect(stageCard.getByText("Take detour")).toBeVisible();
    await expect(stageCard.getByText("Take detour")).toBeDisabled();
  });
});

// ---------------------------------------------------------------------------
// Transition from Acte 2 → Acte 3
// ---------------------------------------------------------------------------

test.describe("StageAlerts — transition from Acte 2", () => {
  test("stage alerts are visible after transitioning from ProcessingProgress via trip_ready", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());

    // Trigger Acte 2 (analysis started + processing)
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

    // Send trip_ready — transitions to Acte 3
    await injectEvent(tripReadyWithThreeAlertsEvent());

    await expect(mockedPage.getByTestId("processing-progress")).toBeHidden({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
    await expect(
      mockedPage.getByTestId("stage-card-1").getByTestId("stage-alerts"),
    ).toBeVisible();
  });

  test("original trip_ready transition still works when stages have no alerts", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());

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

    // trip_ready with empty alerts
    await injectEvent(tripReadyEvent());

    await expect(mockedPage.getByTestId("processing-progress")).toBeHidden({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
    // No alert section when there are no alerts
    await expect(
      mockedPage.getByTestId("stage-card-1").getByTestId("stage-alerts"),
    ).not.toBeVisible();
  });
});
