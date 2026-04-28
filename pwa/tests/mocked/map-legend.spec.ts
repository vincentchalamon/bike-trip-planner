import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

/**
 * Issue #390 — Design Foundations: unified pictogram system + visual legend.
 *
 * Verifies the map legend popover lists all 12 categories and that
 * accommodation markers (the most common case) use the registry.
 */

const ALL_CATEGORIES = [
  "accommodation",
  "water",
  "supply",
  "bike-workshop",
  "railway-station",
  "health",
  "border-crossing",
  "river-crossing",
  "early-departure",
  "cultural-poi",
  "event",
  "user-waypoint",
] as const;

test.describe("Map legend — unified pictogram registry (issue #390)", () => {
  test.beforeEach(async ({ submitUrl, injectSequence, mockedPage }) => {
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
  });

  test("the legend toggle button is visible on the map", async ({
    mockedPage,
  }) => {
    const toggle = mockedPage.getByTestId("map-legend-toggle");
    await expect(toggle).toBeVisible();
  });

  test("opening the legend reveals the 12 categories", async ({
    mockedPage,
  }) => {
    const toggle = mockedPage.getByTestId("map-legend-toggle");
    await toggle.click();

    await expect(mockedPage.getByTestId("map-legend")).toBeVisible();
    const list = mockedPage.getByTestId("map-legend-list");
    await expect(list).toBeVisible();

    for (const category of ALL_CATEGORIES) {
      await expect(
        list.getByTestId(`map-legend-item-${category}`),
      ).toBeVisible();
    }
  });

  test("the map renders at least one accommodation marker", async ({
    mockedPage,
  }) => {
    // accommodationsFoundEvent provides 2 entries — assert at least one is on the map
    const markers = mockedPage.locator(
      '.map-marker--icon[data-category="accommodation"]',
    );
    await expect.poll(() => markers.count()).toBeGreaterThanOrEqual(1);
  });

  test("legend can be closed via Escape key", async ({ mockedPage }) => {
    const toggle = mockedPage.getByTestId("map-legend-toggle");
    await toggle.click();
    await expect(mockedPage.getByTestId("map-legend")).toBeVisible();

    await mockedPage.keyboard.press("Escape");
    await expect(mockedPage.getByTestId("map-legend")).not.toBeVisible();
  });

  test("alert with source renders category icon in list and map marker", async ({
    injectSequence,
    mockedPage,
  }) => {
    // Inject a terrain_alerts event with source "railway_station" on stage 0
    await injectSequence([
      {
        type: "terrain_alerts",
        data: {
          alertsByStage: {
            "0": [
              {
                type: "warning",
                message: "Gare SNCF à proximité",
                source: "railway_station",
                lat: 44.735,
                lon: 4.598,
              },
            ],
          },
        },
      },
    ]);

    // The stage-card-1 should show the alert text
    await expect(mockedPage.getByTestId("stage-card-1")).toContainText(
      "Gare SNCF à proximité",
    );

    // The category icon for railway-station should appear in the alert list
    await expect(
      mockedPage.getByTestId("alert-category-icon-railway-station"),
    ).toBeVisible();

    // The map should render a marker with data-category="railway-station"
    await expect
      .poll(() =>
        mockedPage
          .locator('.map-marker--icon[data-category="railway-station"]')
          .count(),
      )
      .toBeGreaterThanOrEqual(1);
  });
});
