import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  alertsWithActionsEvent,
  terrainAlertsEvent,
  terrainAlertsWithServerFilteredActionsEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Alert actions", () => {
  test("shows action buttons on alerts that have actions", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      alertsWithActionsEvent(),
      tripCompleteEvent(),
    ]);

    // Stage 1 has two alerts with actions: warning + nudge — both groups
    // start collapsed, so we expand them first.
    const stage1 = mockedPage.getByTestId("stage-card-1");
    await stage1.getByTestId("alert-group-toggle-warning").click();
    await stage1.getByTestId("alert-group-toggle-nudge").click();

    await expect(stage1).toContainText("Steep gradient detected (12%)");
    await expect(stage1).toContainText("Minor road surface issue");

    // Both action buttons should be visible
    const actionButtons = stage1.getByTestId("alert-action-button");
    await expect(actionButtons).toHaveCount(2);

    // Navigate action is enabled and opens a map URL
    await expect(stage1.getByText("Zoom to location")).toBeVisible();
    await expect(stage1.getByText("Zoom to location")).not.toBeDisabled();
    await expect(stage1.getByText("Got it")).toBeVisible();
    await expect(stage1.getByText("Got it")).not.toBeDisabled();
  });

  test("dismiss action marks alert as read", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      alertsWithActionsEvent(),
      tripCompleteEvent(),
    ]);

    const stage1 = mockedPage.getByTestId("stage-card-1");

    // Expand the nudge group containing "Minor road surface issue"
    await stage1.getByTestId("alert-group-toggle-nudge").click();

    // Click the dismiss button ("Got it")
    await stage1.getByText("Got it").click();

    // The dismissed alert should have reduced opacity
    const dismissed = stage1.getByTestId("alert-dismissed");
    await expect(dismissed).toBeVisible();

    // The dismiss button should no longer be visible on the dismissed alert
    await expect(stage1.getByText("Got it")).not.toBeVisible();
  });

  test("auto_fix action button is displayed on critical alerts", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      alertsWithActionsEvent(),
      tripCompleteEvent(),
    ]);

    // Stage 2 has a critical alert with auto_fix action — critical group is
    // expanded by default, so no extra click is needed.
    const stage2 = mockedPage.getByTestId("stage-card-2");
    await expect(stage2).toContainText("E-bike range exceeded");
    await expect(stage2.getByText("Split stage")).toBeVisible();
    await expect(stage2.getByText("Split stage")).toBeDisabled();
  });

  test("alerts without actions do not show action buttons", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      terrainAlertsEvent(),
      tripCompleteEvent(),
    ]);

    // terrainAlertsEvent only has alerts on stages 0 and 1; stage 2 (card 3) has none
    const stage3 = mockedPage.getByTestId("stage-card-3");
    await expect(stage3.getByTestId("alert-action-button")).not.toBeVisible();
  });

  test("live terrain alerts expose their navigate action, and none for filtered kinds", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      terrainAlertsWithServerFilteredActionsEvent(),
      tripCompleteEvent(),
    ]);

    // The critical group is expanded by default: the navigate action of a
    // continuity alert must be clickable straight from the live event (#863).
    const stage1 = mockedPage.getByTestId("stage-card-1");
    await expect(stage1).toContainText("Discontinuity between stage 1 and 2");

    const navigate = stage1.getByText("Show the discontinuity on the map");
    await expect(navigate).toBeVisible();
    await expect(navigate).not.toBeDisabled();

    // The elevation alert carries an auto_fix action upstream; it is filtered
    // server-side, so no (dead, disabled) button is rendered.
    const stage2 = mockedPage.getByTestId("stage-card-2");
    await stage2.getByTestId("alert-group-toggle-warning").click();
    await expect(stage2).toContainText("Significant elevation gain (1200m)");
    await expect(stage2.getByTestId("alert-action-button")).toHaveCount(0);
  });

  test("detour action button is displayed and disabled", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      alertsWithActionsEvent(),
      tripCompleteEvent(),
    ]);

    // Stage 3 has a warning with detour action — expand the warning group
    const stage3 = mockedPage.getByTestId("stage-card-3");
    await stage3.getByTestId("alert-group-toggle-warning").click();

    await expect(stage3).toContainText("Difficult terrain ahead");
    await expect(stage3.getByText("Take detour")).toBeVisible();
    await expect(stage3.getByText("Take detour")).toBeDisabled();
  });
});
