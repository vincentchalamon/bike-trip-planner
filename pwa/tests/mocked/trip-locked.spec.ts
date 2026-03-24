import { test, expect } from "../fixtures/base.fixture";
import type { Page } from "@playwright/test";
import { fullTripEventSequence } from "../fixtures/mock-data";

async function mockLockedTrip(page: Page, tripId: string): Promise<void> {
  await page.route("**/trips", async (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 202,
      contentType: "application/ld+json",
      body: JSON.stringify({
        "@context": "/contexts/Trip",
        "@id": `/trips/${tripId}`,
        "@type": "Trip",
        id: tripId,
        computationStatus: {},
        isLocked: true,
      }),
    });
  });

  // Also mock the detail endpoint so TripLoader restores isLocked: true
  await page.route("**/trips/*/detail", async (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/ld+json",
      body: JSON.stringify({
        "@context": "/contexts/TripDetail",
        "@id": `/trips/${tripId}/detail`,
        "@type": "TripDetail",
        id: tripId,
        title: "Test Trip",
        sourceUrl: "https://www.komoot.com/fr-fr/tour/2795080048",
        startDate: null,
        endDate: null,
        fatigueFactor: 0.8,
        elevationPenalty: 100,
        maxDistancePerDay: 80,
        averageSpeed: 15,
        ebikeMode: false,
        departureHour: 8,
        enabledAccommodationTypes: [
          "camp_site",
          "hotel",
          "hostel",
          "chalet",
          "guest_house",
          "motel",
          "alpine_hut",
        ],
        isLocked: true,
        stages: [],
        computationStatus: {},
      }),
    });
  });
}

test.describe("Trip locking", () => {
  test("shows locked banner and hides edit controls when isLocked is true", async ({
    mockedPage,
    injectSequence,
  }) => {
    await mockLockedTrip(mockedPage, "locked-trip-1");

    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
    // Wait for navigation to /trips/[id] and for TripPlanner to mount
    await mockedPage.waitForURL(/\/trips\//, { timeout: 5000 });
    await expect(
      mockedPage
        .getByTestId("trip-title-skeleton")
        .or(mockedPage.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 5000 });

    await injectSequence(fullTripEventSequence());
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
      timeout: 10000,
    });

    // Locked banner should be visible
    await expect(mockedPage.getByTestId("trip-locked-banner")).toBeVisible();

    // Edit controls should be hidden
    await expect(mockedPage.getByTestId("delete-stage-1")).not.toBeVisible();
    await expect(
      mockedPage.getByTestId("add-stage-button-0"),
    ).not.toBeVisible();
    await expect(
      mockedPage.getByTestId("add-rest-day-button-0"),
    ).not.toBeVisible();
  });

  test("does not show locked banner when isLocked is false", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    // Default mock does not set isLocked → banner should be absent
    await expect(
      mockedPage.getByTestId("trip-locked-banner"),
    ).not.toBeVisible();

    // Edit controls should be available
    await expect(mockedPage.getByTestId("delete-stage-1")).toBeVisible();
    await expect(mockedPage.getByTestId("add-stage-button-0")).toBeVisible();
  });

  test("config panel controls are disabled when trip is locked", async ({
    mockedPage,
    injectSequence,
  }) => {
    await mockLockedTrip(mockedPage, "locked-trip-2");

    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
    // Wait for navigation to /trips/[id] and for TripPlanner to mount
    await mockedPage.waitForURL(/\/trips\//, { timeout: 5000 });
    await expect(
      mockedPage
        .getByTestId("trip-title-skeleton")
        .or(mockedPage.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 5000 });

    await injectSequence(fullTripEventSequence());
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
      timeout: 10000,
    });

    // Open config panel — it should be visible but controls disabled
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await expect(
      mockedPage.getByRole("dialog", { name: "Paramètres" }),
    ).toBeInViewport();

    // Date range trigger should be disabled
    await expect(mockedPage.getByTestId("date-range-trigger")).toBeDisabled();
  });
});
