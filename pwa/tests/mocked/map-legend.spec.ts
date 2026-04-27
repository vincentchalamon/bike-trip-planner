import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";
import type { MercureEvent } from "../../src/lib/mercure/types";

/**
 * Issue #390 — Design Foundations: unified pictogram system + visual legend.
 *
 * The legend popover must list the 12 marker categories, and the map must
 * render at least one marker for the categories we can mock here
 * (accommodation + alerts coming from railway/border/cultural_poi sources).
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

/**
 * Crafts a synthetic terrain_alerts event that covers a wide range of
 * `alert.source` values so we can verify the unified registry maps them
 * to the right MarkerCategory. The cast is required because the typed
 * payload doesn't expose `source` / cultural-poi fields, but the runtime
 * Zod schema in `lib/validation/schemas.ts` accepts them.
 */
function multiSourceAlertsEvent(): MercureEvent {
  return {
    type: "terrain_alerts",
    data: {
      alertsByStage: {
        "0": [
          {
            type: "warning",
            message: "Gare SNCF à proximité",
            source: "railway_station",
            lat: 44.6,
            lon: 4.5,
          },
        ],
        "1": [
          {
            type: "warning",
            message: "Passage de frontière en vue",
            source: "border_crossing",
            lat: 44.4,
            lon: 4.2,
          },
        ],
        "2": [
          {
            type: "warning",
            message: "Monument historique remarquable",
            source: "cultural_poi",
            description: "Château médiéval du XIIIᵉ siècle",
            openingHours: "10h-18h",
            estimatedPrice: 8.5,
            lat: 44.295,
            lon: 4.087,
          },
        ],
      },
    },
  } as unknown as MercureEvent;
}

test.describe("Map legend — unified pictogram registry (issue #390)", () => {
  test.beforeEach(async ({ submitUrl, injectSequence, mockedPage }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      multiSourceAlertsEvent(),
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

  test("alert markers use icons resolved from alert.source", async ({
    mockedPage,
  }) => {
    // We injected three alerts (railway / border / cultural). MapView shows one
    // alert marker per stage so we should find one for each of those three
    // categories (or at least the ones with a known mapping).
    const railway = mockedPage.locator(
      '.map-marker--icon[data-category="railway-station"]',
    );
    const border = mockedPage.locator(
      '.map-marker--icon[data-category="border-crossing"]',
    );
    const cultural = mockedPage.locator(
      '.map-marker--icon[data-category="cultural-poi"]',
    );

    await expect.poll(() => railway.count()).toBeGreaterThanOrEqual(1);
    await expect.poll(() => border.count()).toBeGreaterThanOrEqual(1);
    await expect.poll(() => cultural.count()).toBeGreaterThanOrEqual(1);
  });

  test("cultural-poi alert with metadata gets the enriched indicator", async ({
    mockedPage,
  }) => {
    const enriched = mockedPage.locator(".map-marker--cultural-enriched");
    await expect.poll(() => enriched.count()).toBeGreaterThanOrEqual(1);
  });

  test("alert-list shows the resolved category icon next to each badge", async ({
    mockedPage,
  }) => {
    // Focus stage 1 (index 0) so the alert list becomes visible inside the card
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await stageCard.click();

    const railwayIcon = stageCard.getByTestId(
      "alert-category-icon-railway-station",
    );
    await expect(railwayIcon.first()).toBeVisible();
  });

  test("legend can be closed via Escape key", async ({ mockedPage }) => {
    const toggle = mockedPage.getByTestId("map-legend-toggle");
    await toggle.click();
    await expect(mockedPage.getByTestId("map-legend")).toBeVisible();

    await mockedPage.keyboard.press("Escape");
    await expect(mockedPage.getByTestId("map-legend")).not.toBeVisible();
  });
});
