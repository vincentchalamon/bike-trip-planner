/**
 * Issues #325 (Acte 3) and #397 — collapsible stage alerts grouped by
 * severity with contextual action buttons.
 *
 * Tests:
 * - Alerts are grouped by severity: critical → warning → nudge.
 * - Critical group is expanded by default; warning and nudge are collapsed.
 * - Each severity group toggles independently via its chevron.
 * - The whole section can still be collapsed via the parent toggle.
 * - Individual alert actions (auto_fix, navigate, dismiss, detour) work
 *   as expected.
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
            // nudge first in list — should appear in the nudge group regardless
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

/** A trip_ready event with exactly 3 alerts on stage 0 (one per severity). */
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
// Severity grouping
// ---------------------------------------------------------------------------

test.describe("StageAlerts — severity grouping", () => {
  test("alerts are split into critical / warning / nudge groups", async ({
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

    // All three groups should be present
    await expect(stageCard.getByTestId("alert-group-critical")).toBeVisible();
    await expect(stageCard.getByTestId("alert-group-warning")).toBeVisible();
    await expect(stageCard.getByTestId("alert-group-nudge")).toBeVisible();

    // Counts: 2 critical, 3 warning, 2 nudge
    await expect(
      stageCard.getByTestId("alert-group-count-critical"),
    ).toContainText("2");
    await expect(
      stageCard.getByTestId("alert-group-count-warning"),
    ).toContainText("3");
    await expect(
      stageCard.getByTestId("alert-group-count-nudge"),
    ).toContainText("2");
  });

  test("critical group is expanded by default; warning and nudge are collapsed", async ({
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

    // Critical alerts visible, warning and nudge alerts hidden
    await expect(stageCard.getByText("Critical alert C")).toBeVisible();
    await expect(stageCard.getByText("Critical alert F")).toBeVisible();
    await expect(stageCard.getByText("Warning alert B")).not.toBeVisible();
    await expect(stageCard.getByText("Warning alert D")).not.toBeVisible();
    await expect(stageCard.getByText("Nudge alert A")).not.toBeVisible();
    await expect(stageCard.getByText("Nudge alert E")).not.toBeVisible();

    // aria-expanded reflects the default state
    await expect(
      stageCard.getByTestId("alert-group-toggle-critical"),
    ).toHaveAttribute("aria-expanded", "true");
    await expect(
      stageCard.getByTestId("alert-group-toggle-warning"),
    ).toHaveAttribute("aria-expanded", "false");
    await expect(
      stageCard.getByTestId("alert-group-toggle-nudge"),
    ).toHaveAttribute("aria-expanded", "false");
  });

  test("clicking a severity chevron expands its group", async ({
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

    // Expand the warning group
    await stageCard.getByTestId("alert-group-toggle-warning").click();
    await expect(stageCard.getByText("Warning alert B")).toBeVisible();
    await expect(
      stageCard.getByTestId("alert-group-toggle-warning"),
    ).toHaveAttribute("aria-expanded", "true");

    // Expand the nudge group
    await stageCard.getByTestId("alert-group-toggle-nudge").click();
    await expect(stageCard.getByText("Nudge alert A")).toBeVisible();
    await expect(
      stageCard.getByTestId("alert-group-toggle-nudge"),
    ).toHaveAttribute("aria-expanded", "true");
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

  test("severity groups are rendered for non-empty buckets", async ({
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

    // All three buckets have alerts here, so all three groups are present
    await expect(stageCard.getByTestId("alert-group-critical")).toBeVisible();
    await expect(stageCard.getByTestId("alert-group-warning")).toBeVisible();
    await expect(stageCard.getByTestId("alert-group-nudge")).toBeVisible();
  });

  test("severity group with empty bucket is not rendered", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    // Custom fixture: only critical and warning alerts — no nudge bucket.
    // This exercises the `if (count === 0) return null` guard in AlertGroup.
    const tripReadyWithoutNudgeEvent: MercureEvent = {
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
                message: "Critical only 1",
                lat: null,
                lon: null,
              },
              {
                type: "warning",
                message: "Warning only 2",
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
        computationStatus: { route: "done", stages: "done" },
        aiOverview: null,
      },
    };
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripReadyWithoutNudgeEvent,
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("stage-alerts")).toBeVisible({
      timeout: 5000,
    });

    // Critical and warning groups present; nudge group must be absent from DOM.
    await expect(stageCard.getByTestId("alert-group-critical")).toBeVisible();
    await expect(stageCard.getByTestId("alert-group-warning")).toBeVisible();
    await expect(stageCard.getByTestId("alert-group-nudge")).toHaveCount(0);
  });
});

// ---------------------------------------------------------------------------
// Section collapse / expand
// ---------------------------------------------------------------------------

test.describe("StageAlerts — section collapse/expand", () => {
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
// Alert actions (auto_fix, navigate, dismiss, detour)
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

    // "OK" is the dismiss action on "Nudge alert A" — open the nudge group first
    await stageCard.getByTestId("alert-group-toggle-nudge").click();

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

    // "Zoom to location" is the navigate action on "Warning alert B" —
    // expand the warning group first.
    await stageCard.getByTestId("alert-group-toggle-warning").click();
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

    // "Split stage" is the auto_fix action on "Critical alert C" — critical
    // group is expanded by default, so the button is immediately visible.
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

    // "Warning alert D" carries the detour action — expand the warning group
    await stageCard.getByTestId("alert-group-toggle-warning").click();

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
