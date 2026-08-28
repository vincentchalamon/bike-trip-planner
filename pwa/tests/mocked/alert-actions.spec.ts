import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  alertsWithActionsEvent,
  terrainAlertsEvent,
  terrainAlertWithSegmentsEvent,
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

  test("auto_fix action is not surfaced on critical alerts (unwired kind)", async ({
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

    // Stage 2 has a critical alert with an auto_fix action — critical group is
    // expanded by default. The alert renders, but the unwired action does not.
    const stage2 = mockedPage.getByTestId("stage-card-2");
    await expect(stage2).toContainText("E-bike range exceeded");
    await expect(stage2.getByText("Split stage")).toHaveCount(0);
    await expect(stage2.getByTestId("alert-action-button")).toHaveCount(0);
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

  test("navigate action highlights the concerned segment on the internal map", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      terrainAlertWithSegmentsEvent(),
      tripCompleteEvent(),
    ]);

    // The critical group is expanded by default; the map starts with nothing
    // highlighted.
    const mapView = mockedPage.getByTestId("map-view");
    await expect(mapView).toHaveAttribute("data-alert-segment", "");

    const stage1 = mockedPage.getByTestId("stage-card-1");
    await stage1.getByText("See the segment on the map").click();

    // The clicked segment is now highlighted (one polyline) and the reset-view
    // affordance appears — no external OSM tab is opened.
    await expect(mapView).toHaveAttribute("data-alert-segment", "1");
    await expect(mockedPage.getByTestId("map-reset-view")).toBeVisible();

    // Resetting clears the highlight.
    await mockedPage.getByTestId("map-reset-view").click();
    await expect(mapView).toHaveAttribute("data-alert-segment", "");
  });

  test("navigate on a point-only alert recenters the internal map (no OSM tab)", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      // The discontinuity alert carries only a point (lat/lon), no `segments`.
      terrainAlertsWithServerFilteredActionsEvent(),
      tripCompleteEvent(),
    ]);

    const mapView = mockedPage.getByTestId("map-view");
    await expect(mapView).toHaveAttribute("data-alert-segment", "");

    // A blank tab would open on window.open; assert none is created.
    let openedExternally = false;
    mockedPage.on("popup", () => {
      openedExternally = true;
    });

    const stage1 = mockedPage.getByTestId("stage-card-1");
    await stage1.getByText("Show the discontinuity on the map").click();

    // The point recenters the internal map (a single one-coordinate focus) and the
    // reset affordance appears — the OSM external tab is gone (#982).
    await expect(mapView).toHaveAttribute("data-alert-segment", "1");
    await expect(mockedPage.getByTestId("map-reset-view")).toBeVisible();
    expect(openedExternally).toBe(false);

    await mockedPage.getByTestId("map-reset-view").click();
    await expect(mapView).toHaveAttribute("data-alert-segment", "");
  });

  test("detour action is not surfaced (unwired kind)", async ({
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

    // Stage 3 has a warning with a detour action — expand the warning group.
    // The alert renders, but the unwired action does not.
    const stage3 = mockedPage.getByTestId("stage-card-3");
    await stage3.getByTestId("alert-group-toggle-warning").click();

    await expect(stage3).toContainText("Difficult terrain ahead");
    await expect(stage3.getByText("Take detour")).toHaveCount(0);
    await expect(stage3.getByTestId("alert-action-button")).toHaveCount(0);
  });
});
