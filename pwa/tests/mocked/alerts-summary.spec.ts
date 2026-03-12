import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  terrainAlertsEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";
import type { MercureEvent } from "../../src/lib/mercure/types";

function mixedAlertsEvent(): MercureEvent {
  return {
    type: "terrain_alerts",
    data: {
      alertsByStage: {
        "0": [
          {
            type: "critical",
            message: "Danger critique sur l'étape",
            lat: 44.6,
            lon: 4.5,
          },
          {
            type: "nudge",
            message: "Passage recommandé en matinée",
            lat: null,
            lon: null,
          },
        ],
        "1": [
          {
            type: "warning",
            message: "Route non goudronnée sur 5km",
            lat: 44.4,
            lon: 4.2,
          },
          {
            type: "nudge",
            message: "Curiosité locale à proximité",
            lat: null,
            lon: null,
          },
        ],
      },
    },
  };
}

test.describe("Alerts summary panel", () => {
  test("shows alerts and suggestions in separate sections", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      mixedAlertsEvent(),
      tripCompleteEvent(),
    ]);

    const panel = mockedPage.getByTestId("alerts-summary-panel");
    await expect(panel).toBeVisible();

    // Alerts section (critical + warning)
    await expect(panel.getByText("Alertes")).toBeVisible();
    await expect(panel.getByText("Danger critique sur l'étape")).toBeVisible();
    await expect(panel.getByText("Route non goudronnée sur 5km")).toBeVisible();

    // Suggestions section (nudge)
    await expect(panel.getByText("Suggestions et détections")).toBeVisible();
    await expect(
      panel.getByText("Passage recommandé en matinée"),
    ).toBeVisible();
    await expect(panel.getByText("Curiosité locale à proximité")).toBeVisible();
  });

  test("shows only alerts section when no nudges", async ({
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

    const panel = mockedPage.getByTestId("alerts-summary-panel");
    await expect(panel).toBeVisible();

    // terrainAlertsEvent has a warning on stage 0 and nudge on stage 1
    await expect(panel.getByText("Alertes")).toBeVisible();
    await expect(panel.getByText("Suggestions et détections")).toBeVisible();
  });

  test("does not show panel when no alerts", async ({
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

    const panel = mockedPage.getByTestId("alerts-summary-panel");
    await expect(panel).not.toBeVisible();
  });

  test("deduplicates identical alerts across stages", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    const duplicateAlerts: MercureEvent = {
      type: "terrain_alerts",
      data: {
        alertsByStage: {
          "0": [
            {
              type: "warning",
              message: "Message identique",
              lat: 44.6,
              lon: 4.5,
            },
          ],
          "1": [
            {
              type: "warning",
              message: "Message identique",
              lat: 44.4,
              lon: 4.2,
            },
          ],
        },
      },
    };

    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      duplicateAlerts,
      tripCompleteEvent(),
    ]);

    const panel = mockedPage.getByTestId("alerts-summary-panel");
    await expect(panel).toBeVisible();

    // Should appear only once
    await expect(panel.getByText("Message identique")).toHaveCount(1);
  });
});
