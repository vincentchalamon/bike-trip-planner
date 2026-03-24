import { test, expect } from "../fixtures/base.fixture";
import { fullTripEventSequence } from "../fixtures/mock-data";

test.describe("Trip locking", () => {
  test("shows locked banner and hides edit controls when isLocked is true", async ({
    mockedPage,
    injectSequence,
  }) => {
    // Override POST /trips to return isLocked: true
    await mockedPage.route("**/trips", async (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 202,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@context": "/contexts/Trip",
          "@id": "/trips/locked-trip-1",
          "@type": "Trip",
          id: "locked-trip-1",
          computationStatus: {},
          isLocked: true,
        }),
      });
    });

    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
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
    // Override POST /trips to return isLocked: true
    await mockedPage.route("**/trips", async (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 202,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@context": "/contexts/Trip",
          "@id": "/trips/locked-trip-2",
          "@type": "Trip",
          id: "locked-trip-2",
          computationStatus: {},
          isLocked: true,
        }),
      });
    });

    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
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
