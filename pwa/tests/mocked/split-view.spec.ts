import { test, expect } from "../fixtures/base.fixture";
import type { MercureEvent } from "../../src/lib/mercure/types";

/**
 * Stages with geometry — required for map rendering and the view-mode toggle.
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
            { lat: 44.38, lon: 4.2, ele: 480 },
            { lat: 44.295, lon: 4.087, ele: 360 },
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

test.describe("ViewModeToggle visibility", () => {
  test("toggle is not visible before a trip is loaded", async ({
    mockedPage,
  }) => {
    await expect(mockedPage.getByTestId("view-mode-toggle")).not.toBeVisible();
  });

  test("toggle appears after stages with geometry are computed", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    await expect(mockedPage.getByTestId("view-mode-toggle")).toBeVisible({
      timeout: 5000,
    });
  });
});

test.describe("ViewModeToggle — mode switching", () => {
  test("clicking map button shows map and hides timeline", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    const toggle = mockedPage.getByTestId("view-mode-toggle");
    await expect(toggle).toBeVisible({
      timeout: 5000,
    });

    await toggle.getByTestId("view-mode-map").click();

    await expect(mockedPage.getByTestId("map-container")).toBeVisible({
      timeout: 3000,
    });
    await expect(mockedPage.locator("#timeline")).not.toBeVisible();
  });

  test("clicking timeline button shows timeline and hides map", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    const toggle = mockedPage.getByTestId("view-mode-toggle");
    await expect(toggle).toBeVisible({
      timeout: 5000,
    });

    // Switch to timeline-only mode
    await toggle.getByTestId("view-mode-timeline").click();

    await expect(mockedPage.locator("#timeline")).toBeVisible({
      timeout: 3000,
    });
    await expect(mockedPage.getByTestId("map-container")).not.toBeVisible();
  });

  test("clicking split button shows both timeline and map", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    const toggle = mockedPage.getByTestId("view-mode-toggle");
    await expect(toggle).toBeVisible({
      timeout: 5000,
    });

    // First switch away from split
    await toggle.getByTestId("view-mode-timeline").click();
    await expect(mockedPage.getByTestId("map-container")).not.toBeVisible();

    // Then switch back to split
    await toggle.getByTestId("view-mode-split").click();

    await expect(mockedPage.locator("#timeline")).toBeVisible({
      timeout: 3000,
    });
    await expect(mockedPage.getByTestId("map-container")).toBeVisible({
      timeout: 3000,
    });
  });

  test("active button has pressed state", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    const toggle = mockedPage.getByTestId("view-mode-toggle");
    await expect(toggle).toBeVisible({
      timeout: 5000,
    });

    await toggle.getByTestId("view-mode-map").click();

    await expect(toggle.getByTestId("view-mode-map")).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    await expect(toggle.getByTestId("view-mode-timeline")).toHaveAttribute(
      "aria-pressed",
      "false",
    );
    await expect(toggle.getByTestId("view-mode-split")).toHaveAttribute(
      "aria-pressed",
      "false",
    );
  });
});

test.describe("swipe gestures (mobile)", () => {
  async function swipeHorizontal(
    page: import("@playwright/test").Page,
    direction: "left" | "right",
  ) {
    // Dispatch touchstart/touchend on the split-view-container so React's onTouchStart/onTouchEnd
    // handlers in the useSwipe hook are triggered. locator.dispatchEvent targets the element
    // directly, so the event bubbles up to React's root listener as expected.
    const startX = direction === "left" ? 300 : 100;
    const endX = direction === "left" ? 100 : 300;
    const container = page.getByTestId("split-view-container");
    await container.dispatchEvent("touchstart", {
      touches: [{ identifier: 1, clientX: startX, clientY: 300 }],
      changedTouches: [{ identifier: 1, clientX: startX, clientY: 300 }],
    });
    await container.dispatchEvent("touchend", {
      touches: [],
      changedTouches: [{ identifier: 1, clientX: endX, clientY: 300 }],
    });
  }

  test("swipe left switches viewMode to map", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    const toggle = mockedPage.getByTestId("view-mode-toggle");
    await expect(toggle).toBeVisible({
      timeout: 5000,
    });

    // Start from split (default on desktop) — switch to timeline first so swipe left → map makes sense
    await toggle.getByTestId("view-mode-timeline").click();
    await expect(mockedPage.getByTestId("map-container")).not.toBeVisible();

    await swipeHorizontal(mockedPage, "left");

    await expect(mockedPage.getByTestId("map-container")).toBeVisible({
      timeout: 3000,
    });
  });

  test("swipe right switches viewMode to timeline", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await createTripWithGeometry(submitUrl, injectEvent);
    const toggle = mockedPage.getByTestId("view-mode-toggle");
    await expect(toggle).toBeVisible({
      timeout: 5000,
    });

    // Start from map-only mode so swipe right → timeline makes sense
    await toggle.getByTestId("view-mode-map").click();
    await expect(mockedPage.locator("#timeline")).not.toBeVisible();

    await swipeHorizontal(mockedPage, "right");

    await expect(mockedPage.locator("#timeline")).toBeVisible({
      timeout: 3000,
    });
  });
});

test.describe("ViewModeToggle — desktop default", () => {
  test("default mode on desktop (≥1024px) is split", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    // Playwright default viewport is 1280×720 — desktop
    await createTripWithGeometry(submitUrl, injectEvent);
    const toggle = mockedPage.getByTestId("view-mode-toggle");
    await expect(toggle).toBeVisible({
      timeout: 5000,
    });

    // Both timeline and map should be visible in split mode
    await expect(mockedPage.locator("#timeline")).toBeVisible({
      timeout: 3000,
    });
    await expect(mockedPage.getByTestId("map-container")).toBeVisible({
      timeout: 3000,
    });

    await expect(toggle.getByTestId("view-mode-split")).toHaveAttribute(
      "aria-pressed",
      "true",
    );
  });

  test("default mode on mobile (<1024px) is timeline-only", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    // Force a mobile viewport — below the 1024px breakpoint
    await mockedPage.setViewportSize({ width: 375, height: 812 });
    await createTripWithGeometry(submitUrl, injectEvent);
    const toggle = mockedPage.getByTestId("view-mode-toggle");
    await expect(toggle).toBeVisible({
      timeout: 5000,
    });

    // On mobile, ViewModeToggle sets viewMode to "timeline" on mount
    await expect(mockedPage.locator("#timeline")).toBeVisible({
      timeout: 3000,
    });
    await expect(mockedPage.getByTestId("map-container")).not.toBeVisible();
    await expect(toggle.getByTestId("view-mode-timeline")).toHaveAttribute(
      "aria-pressed",
      "true",
    );
  });
});
