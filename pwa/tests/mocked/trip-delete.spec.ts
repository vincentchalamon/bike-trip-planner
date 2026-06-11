import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

/**
 * E2E coverage for deleting a trip from the trip itself (recette #649).
 *
 * A red "Supprimer le voyage" button sits below "Partager ce voyage" in the
 * configuration drawer. It opens a destructive confirmation dialog and, on
 * confirm, calls `DELETE /trips/{id}` (the same endpoint as the "Mes voyages"
 * list) before navigating back to `/trips`.
 */
const TRIP_ID = "01936f6e-0000-7000-8000-0000000005de";

const MOCK_DETAIL = {
  "@context": "/contexts/TripDetail",
  "@id": `/trips/${TRIP_ID}/detail`,
  "@type": "TripDetail",
  id: TRIP_ID,
  title: "Tour des Cévennes",
  sourceUrl: "https://www.komoot.com/tour/123456789",
  startDate: "2026-07-01T00:00:00+00:00",
  endDate: "2026-07-03T00:00:00+00:00",
  fatigueFactor: 0.9,
  elevationPenalty: 50,
  maxDistancePerDay: 80,
  averageSpeed: 15,
  ebikeMode: false,
  departureHour: 8,
  enabledAccommodationTypes: [],
  isLocked: false,
  stages: [
    {
      dayNumber: 1,
      distance: 60,
      elevation: 800,
      elevationLoss: 600,
      startPoint: { lat: 44.1, lon: 3.6, ele: 300 },
      endPoint: { lat: 44.3, lon: 3.9, ele: 400 },
      geometry: [
        { lat: 44.1, lon: 3.6, ele: 300 },
        { lat: 44.3, lon: 3.9, ele: 400 },
      ],
      label: null,
      isRestDay: false,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      selectedAccommodation: null,
    },
    {
      dayNumber: 2,
      distance: 55,
      elevation: 700,
      elevationLoss: 750,
      startPoint: { lat: 44.3, lon: 3.9, ele: 400 },
      endPoint: { lat: 44.5, lon: 4.1, ele: 350 },
      geometry: [
        { lat: 44.3, lon: 3.9, ele: 400 },
        { lat: 44.5, lon: 4.1, ele: 350 },
      ],
      label: null,
      isRestDay: false,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      selectedAccommodation: null,
    },
  ],
  computationStatus: {},
};

test.describe("Delete trip from the config panel", () => {
  test.beforeEach(async ({ page }) => {
    await page.route("**/auth/refresh", (route) =>
      route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
      }),
    );
    await page.route(`**/trips/${TRIP_ID}/detail`, (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify(MOCK_DETAIL),
      });
    });
  });

  async function openConfig(page: import("@playwright/test").Page) {
    await page.goto(`/trips/${TRIP_ID}`);
    await expect(page.getByTestId("config-open-button")).toBeVisible({
      timeout: 10000,
    });
    await page.getByTestId("config-open-button").click();
    await expect(page.getByTestId("delete-trip-button")).toBeVisible();
  }

  test("confirming deletion calls the API and navigates to the trips list", async ({
    page,
  }) => {
    let deleteCalled = false;
    await page.route(`**/trips/${TRIP_ID}`, (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      deleteCalled = true;
      return route.fulfill({ status: 204, body: "" });
    });
    // The app navigates to /trips after deletion; stub the list fetch so the
    // destination page settles instead of hitting the network.
    await page.route(/\/trips\?/, (route, request) => {
      if (request.method() !== "GET") return route.fallback();
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify({ member: [], totalItems: 0 }),
      });
    });

    await openConfig(page);
    await page.getByTestId("delete-trip-button").click();
    await expect(page.getByTestId("delete-trip-dialog")).toBeVisible();
    await page.getByTestId("delete-trip-dialog-confirm").click();

    await page.waitForURL(/\/trips$/, { timeout: 5000 });
    expect(deleteCalled).toBe(true);
  });

  test("shows an error toast when the deletion fails", async ({ page }) => {
    await page.route(`**/trips/${TRIP_ID}`, (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      return route.fulfill({ status: 500, body: "" });
    });

    await openConfig(page);
    await page.getByTestId("delete-trip-button").click();
    await expect(page.getByTestId("delete-trip-dialog")).toBeVisible();
    await page.getByTestId("delete-trip-dialog-confirm").click();

    await expect(page.getByText(/impossible de supprimer/i)).toBeVisible({
      timeout: 5000,
    });
    // No navigation on failure.
    await expect(page).toHaveURL(new RegExp(`/trips/${TRIP_ID}`));
  });
});
