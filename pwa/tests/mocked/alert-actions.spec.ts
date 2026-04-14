import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  alertsWithActionsEvent,
  terrainAlertsEvent,
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

    // Stage 1 has two alerts with actions
    const stage1 = mockedPage.getByTestId("stage-card-1");
    await expect(stage1).toContainText("Steep gradient detected (12%)");
    await expect(stage1).toContainText("Minor road surface issue");

    // Both action buttons should be visible
    const actionButtons = stage1.getByTestId("alert-action-button");
    await expect(actionButtons).toHaveCount(2);

    // Verify labels
    await expect(stage1.getByText("Zoom to location")).toBeVisible();
    await expect(stage1.getByText("Got it")).toBeVisible();
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

    // Stage 2 has a critical alert with auto_fix action
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

    // terrainAlertsEvent has alerts on stages 0 and 1 but without actions
    const stage1 = mockedPage.getByTestId("stage-card-1");
    await expect(stage1).toContainText("Route non goudronnee sur 3km");
    await expect(stage1.getByTestId("alert-action-button")).not.toBeVisible();
  });
});
