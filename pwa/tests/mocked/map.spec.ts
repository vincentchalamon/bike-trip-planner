import { test, expect } from "../fixtures/base.fixture";
import type { MercureEvent } from "../../src/lib/mercure/types";

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
    await mockedPage.getByTestId("view-mode-toggle").getByTestId("view-mode-map").click();

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
