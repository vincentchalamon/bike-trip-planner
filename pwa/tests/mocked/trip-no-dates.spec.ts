import { test, expect } from "../fixtures/base.fixture";
import { fullTripEventSequence } from "../fixtures/mock-data";

test.describe("No-dates banner", () => {
  test("banner is visible when startDate is null", async ({
    createFullTrip,
    mockedPage,
  }) => {
    // Default mock has startDate: null
    await createFullTrip();

    await expect(mockedPage.getByTestId("no-dates-banner")).toBeVisible();
  });

  test("clicking CTA button opens config panel", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await expect(mockedPage.getByTestId("no-dates-banner")).toBeVisible();

    // Click "Définir les dates" CTA in the banner
    await mockedPage.getByTestId("no-dates-banner").getByRole("button").click();

    // Config panel should open
    await expect(
      mockedPage.getByRole("dialog", { name: "Paramètres" }),
    ).toBeInViewport();
  });

  test("banner is absent when trip has a startDate", async ({
    mockedPage,
    injectSequence,
  }) => {
    // Override detail mock to return a startDate
    await mockedPage.route("**/trips/*/detail", async (route, request) => {
      if (request.method() !== "GET") return route.fallback();
      const tripId =
        request.url().match(/\/trips\/([^/]+)\/detail/)?.[1] ?? "test-trip";
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
          startDate: "2026-07-01T00:00:00+00:00",
          endDate: "2026-07-07T00:00:00+00:00",
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
          isLocked: false,
          stages: [],
          computationStatus: {},
        }),
      });
    });

    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
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

    await expect(mockedPage.getByTestId("no-dates-banner")).not.toBeVisible();
  });
});
