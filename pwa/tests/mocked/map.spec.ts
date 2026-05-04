import { test, expect, expandLinkCard } from "../fixtures/base.fixture";
import { mockAllApis } from "../fixtures/api-mocks";
import { injectSseEvent } from "../fixtures/sse-helpers";
import type { MercureEvent } from "../../src/lib/mercure/types";
import { culturalPoiAlertsEvent } from "../fixtures/mock-data";

/**
 * Stages with geometry — required for the map to render routes and elevation profile.
 */
function stagesWithGeometryEvent(): MercureEvent {
  return {
    type: "stages_computed",
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
            { lat: 44.68, lon: 4.53, ele: 420 },
            { lat: 44.62, lon: 4.46, ele: 650 },
            { lat: 44.58, lon: 4.42, ele: 520 },
            { lat: 44.532, lon: 4.392, ele: 540 },
          ],
          label: null,
        },
        {
          dayNumber: 2,
          distance: 63.2,
          elevation: 870,
          elevationLoss: 1050,
          startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
          endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
          geometry: [
            { lat: 44.532, lon: 4.392, ele: 540 },
            { lat: 44.46, lon: 4.3, ele: 700 },
            { lat: 44.38, lon: 4.2, ele: 480 },
            { lat: 44.295, lon: 4.087, ele: 360 },
          ],
          label: null,
        },
        {
          dayNumber: 3,
          distance: 51.6,
          elevation: 800,
          elevationLoss: 750,
          startPoint: { lat: 44.295, lon: 4.087, ele: 360 },
          endPoint: { lat: 44.112, lon: 3.876, ele: 410 },
          geometry: [
            { lat: 44.295, lon: 4.087, ele: 360 },
            { lat: 44.22, lon: 3.99, ele: 520 },
            { lat: 44.112, lon: 3.876, ele: 410 },
          ],
          label: null,
        },
      ],
    },
  };
}

async function createTripWithGeometry(
  submitUrl: () => Promise<void>,
  injectEvent: (e: MercureEvent) => Promise<void>,
) {
  await submitUrl();
  await injectEvent(stagesWithGeometryEvent());
}

test.describe("Map panel", () => {
  test("map panel is not visible before a trip is loaded", async ({
    mockedPage,
  }) => {
    await expect(mockedPage.getByTestId("map-panel")).not.toBeVisible();
  });

  test("map panel appears after stages are computed", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("map-panel")).toBeVisible({
      timeout: 5000,
    });
  });

  test("map container is rendered inside map panel", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("map-container")).toBeVisible({
      timeout: 5000,
    });
  });

  test("MapView element is present", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("map-view")).toBeVisible({
      timeout: 5000,
    });
  });
});

test.describe("Elevation profile", () => {
  test("elevation profile is not shown when there is no geometry", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    // Inject stages without geometry
    await injectEvent({
      type: "stages_computed",
      data: {
        stages: [
          {
            dayNumber: 1,
            distance: 72.5,
            elevation: 1180,
            elevationLoss: 920,
            startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
            endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
            geometry: [], // no geometry
            label: null,
          },
        ],
      },
    });
    // elevation profile is not rendered when geometry is empty
    await expect(mockedPage.getByTestId("elevation-profile")).not.toBeVisible();
  });

  test("elevation profile appears with geometry data", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("elevation-profile")).toBeVisible({
      timeout: 5000,
    });
  });

  test("crosshair and tooltip appear on hover", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);

    // Switch to map-only view so the elevation profile is fully visible
    await mockedPage
      .getByTestId("view-mode-toggle")
      .getByTestId("view-mode-map")
      .click();

    const profile = mockedPage.getByTestId("elevation-profile");
    await expect(profile).toBeVisible({ timeout: 5000 });

    const svg = profile.locator("svg");
    // hover() properly dispatches mousemove to the element
    await svg.hover();

    // SVG <line> crosshair — vertical line has zero geometric width, use toBeAttached()
    await expect(svg.getByTestId("elevation-crosshair")).toBeAttached();
    // HTML tooltip div — rendered outside the SVG, scoped to the profile container
    await expect(profile.getByTestId("elevation-tooltip-bg")).toBeVisible();
  });
});

test.describe("Tile layer control", () => {
  test("tile-layer-control is visible after stages are loaded", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("tile-layer-control")).toBeVisible({
      timeout: 5000,
    });
  });

  test("clicking Satellite sets it as the active pill", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("tile-layer-control")).toBeVisible({
      timeout: 5000,
    });

    await mockedPage.getByTestId("tile-layer-satellite").click();

    await expect(
      mockedPage.getByTestId("tile-layer-satellite"),
    ).toHaveAttribute("aria-checked", "true");
    await expect(mockedPage.getByTestId("tile-layer-map")).toHaveAttribute(
      "aria-checked",
      "false",
    );
  });

  test("clicking Map restores it as the active pill", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("tile-layer-control")).toBeVisible({
      timeout: 5000,
    });

    // Switch to satellite first
    await mockedPage.getByTestId("tile-layer-satellite").click();
    await expect(
      mockedPage.getByTestId("tile-layer-satellite"),
    ).toHaveAttribute("aria-checked", "true");

    // Switch back to map
    await mockedPage.getByTestId("tile-layer-map").click();
    await expect(mockedPage.getByTestId("tile-layer-map")).toHaveAttribute(
      "aria-checked",
      "true",
    );
    await expect(
      mockedPage.getByTestId("tile-layer-satellite"),
    ).toHaveAttribute("aria-checked", "false");
  });

  test("satellite pill is pre-selected when localStorage preference is satellite", async ({
    page,
  }) => {
    // addInitScript must be called before goto() — use page directly (no mockedPage fixture).
    await mockAllApis(page);
    await page.addInitScript(() => {
      localStorage.setItem("bike-trip-planner:map.tileMode", "satellite");
    });
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    await expandLinkCard(page);

    // Submit a URL and inject geometry stages
    const input = page.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
    await page.waitForURL(/\/trips\//, { timeout: 5000 });
    // Wait for the trip detail page to be loaded (matches submitUrl fixture)
    // before injecting SSE so the listener is attached on the new page.
    await expect(
      page
        .getByTestId("trip-title-skeleton")
        .or(page.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 5000 });
    await injectSseEvent(page, stagesWithGeometryEvent());

    await expect(page.getByTestId("tile-layer-control")).toBeVisible({
      timeout: 5000,
    });
    await expect(page.getByTestId("tile-layer-satellite")).toHaveAttribute(
      "aria-checked",
      "true",
    );
  });

  test("clicking Satellite writes preference to localStorage", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("tile-layer-control")).toBeVisible({
      timeout: 5000,
    });

    await mockedPage.getByTestId("tile-layer-satellite").click();

    const stored = await mockedPage.evaluate(() =>
      localStorage.getItem("bike-trip-planner:map.tileMode"),
    );
    expect(stored).toBe("satellite");
  });

  test("ArrowRight from Map moves focus and selection to Satellite", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("tile-layer-control")).toBeVisible({
      timeout: 5000,
    });

    // Focus the Map pill (it is the initially active pill)
    await mockedPage.getByTestId("tile-layer-map").focus();

    // Press ArrowRight on the radiogroup container
    await mockedPage.keyboard.press("ArrowRight");

    await expect(
      mockedPage.getByTestId("tile-layer-satellite"),
    ).toHaveAttribute("aria-checked", "true");
    await expect(mockedPage.getByTestId("tile-layer-map")).toHaveAttribute(
      "aria-checked",
      "false",
    );
  });
});

/**
 * Enriched cultural POI event — carries the optional Wikidata / DataTourisme
 * fields recognised by `isEnrichedPoi`, so the popover renders variant A
 * (image, description, opening hours, price, Wikipedia link).
 *
 * Coordinates align with the first stage geometry above so the marker is
 * inside the rendered map viewport.
 */
function enrichedCulturalPoiAlertsEvent(): MercureEvent {
  return {
    type: "cultural_poi_alerts",
    data: {
      alerts: [
        {
          stageIndex: 0,
          dayNumber: 1,
          type: "nudge",
          message: "Cultural POI nearby: Château de Ventadour",
          lat: 44.71,
          lon: 4.57,
          poiName: "Château de Ventadour",
          poiType: "castle",
          poiLat: 44.71,
          poiLon: 4.57,
          distanceFromRoute: 320,
          description:
            "Forteresse médiévale du XIIe siècle perchée sur un éperon rocheux dominant la vallée de l'Ardèche.",
          openingHours: "24/7",
          estimatedPrice: 5,
          imageUrl: "https://example.com/ventadour.jpg",
          wikidataId: "Q3577836",
          wikipediaUrl:
            "https://fr.wikipedia.org/wiki/Ch%C3%A2teau_de_Ventadour",
        },
      ],
    },
  };
}

test.describe("Cultural POI popover", () => {
  test("clicking an enriched POI marker opens the popover (variant A)", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      stagesWithGeometryEvent(),
      enrichedCulturalPoiAlertsEvent(),
    ]);

    const marker = mockedPage.getByTestId("cultural-poi-marker").first();
    await expect(marker).toBeVisible({ timeout: 5000 });
    await marker.click();

    const popover = mockedPage.getByTestId("poi-popover");
    await expect(popover).toBeVisible();
    await expect(popover).toHaveAttribute("data-variant", "enriched");

    // Variant A — enrichment fields rendered.
    await expect(popover.getByTestId("poi-popover-title")).toHaveText(
      "Château de Ventadour",
    );
    await expect(popover.getByTestId("poi-popover-description")).toBeVisible();
    await expect(popover.getByTestId("poi-popover-image")).toBeVisible();
    await expect(popover.getByTestId("poi-popover-wikipedia")).toBeVisible();
  });

  test("close button dismisses the popover", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      stagesWithGeometryEvent(),
      enrichedCulturalPoiAlertsEvent(),
    ]);

    const marker = mockedPage.getByTestId("cultural-poi-marker").first();
    await marker.click();

    const popover = mockedPage.getByTestId("poi-popover");
    await expect(popover).toBeVisible();

    const closeButton = popover.getByTestId("poi-popover-close");
    await expect(closeButton).toBeVisible();
    await expect(closeButton).toBeEnabled();
    // The popover is anchored to a pulsating marker inside an animating map view,
    // making the close button position never fully stable. Dispatch the click
    // directly via the DOM API to bypass Playwright's geometric hit-testing —
    // the React onClick handler still fires and dismisses the popover.
    await closeButton.evaluate((btn: HTMLElement) => btn.click());
    await expect(mockedPage.getByTestId("poi-popover")).toHaveCount(0);
  });

  test("non-enriched POI marker opens the minimal variant (variant B)", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    // `culturalPoiAlertsEvent` carries no enrichment fields → minimal variant.
    await injectSequence([stagesWithGeometryEvent(), culturalPoiAlertsEvent()]);

    const marker = mockedPage.getByTestId("cultural-poi-marker").first();
    await expect(marker).toBeVisible({ timeout: 5000 });
    await marker.click();

    const popover = mockedPage.getByTestId("poi-popover");
    await expect(popover).toBeVisible();
    await expect(popover).toHaveAttribute("data-variant", "minimal");

    // Variant B — enrichment fields absent.
    await expect(popover.getByTestId("poi-popover-title")).toHaveText(
      "Château de Ventadour",
    );
    await expect(popover.getByTestId("poi-popover-description")).toHaveCount(0);
    await expect(popover.getByTestId("poi-popover-image")).toHaveCount(0);
    await expect(popover.getByTestId("poi-popover-wikipedia")).toHaveCount(0);
  });
});

test.describe("Map reset view button", () => {
  test("reset view button is not shown in global view", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("map-reset-view")).not.toBeVisible();
  });

  test("reset view button appears when a stage is focused", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    // Trigger via CustomEvent (works in production builds, consistent with __test_mercure_event pattern)
    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_focused_map_stage", { detail: 0 }),
      );
    });
    await expect(mockedPage.getByTestId("map-reset-view")).toBeVisible({
      timeout: 3000,
    });
  });
});
