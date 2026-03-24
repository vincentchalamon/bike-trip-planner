import { test, expect } from "@playwright/test";

const TRIP_ID = "01936f6e-0000-7000-8000-000000000101";

const MOCK_DETAIL = {
  id: TRIP_ID,
  title: "Tour des Alpes",
  sourceUrl: "https://www.komoot.com/tour/123456789",
  startDate: "2025-07-01T00:00:00+00:00",
  endDate: "2025-07-07T00:00:00+00:00",
  fatigueFactor: 0.9,
  elevationPenalty: 50,
  maxDistancePerDay: 80,
  averageSpeed: 15,
  ebikeMode: false,
  departureHour: 8,
  enabledAccommodationTypes: [],
  stages: [],
};

test.describe("/trips/[id] detail page", () => {
  test("renders back button after successful load", async ({ page }) => {
    await page.route(`**/trips/${TRIP_ID}/detail`, (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify(MOCK_DETAIL),
      });
    });

    await page.goto(`/trips/${TRIP_ID}`);
    // TripPlanner may keep SSE connections open — do not wait for networkidle
    await expect(
      page.getByRole("link", { name: /retour aux voyages/i }),
    ).toBeVisible({ timeout: 10000 });
  });

  test("shows error state when API call fails", async ({ page }) => {
    await page.route(`**/trips/${TRIP_ID}/detail`, (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      return route.fulfill({ status: 500, body: "" });
    });

    await page.goto(`/trips/${TRIP_ID}`);
    await page.waitForLoadState("networkidle");

    await expect(page.getByText(/impossible de charger/i)).toBeVisible({
      timeout: 5000,
    });
    await expect(
      page.getByRole("link", { name: /retour aux voyages/i }),
    ).toBeVisible();
  });
});
