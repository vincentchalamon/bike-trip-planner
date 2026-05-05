import { test, expect } from "@playwright/test";

const SHORT_CODE = "Br3t4gn3";

const MOCK_SHARED_TRIP = {
  "@context": "/contexts/TripShare",
  "@type": "TripDetail",
  id: "share-uuid-abc-999",
  title: "Tour de Bretagne",
  startDate: "2026-07-01T00:00:00+00:00",
  endDate: "2026-07-02T00:00:00+00:00",
  fatigueFactor: 0.85,
  elevationPenalty: 40,
  maxDistancePerDay: 70,
  averageSpeed: 16,
  stages: [
    {
      dayNumber: 1,
      distance: 68.5,
      elevation: 920,
      elevationLoss: 850,
      startPoint: { lat: 47.998, lon: -4.098, ele: 80 },
      endPoint: { lat: 47.742, lon: -3.367, ele: 60 },
      geometry: [],
      label: null,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      selectedAccommodation: null,
      isRestDay: false,
    },
    {
      dayNumber: 2,
      distance: 54.2,
      elevation: 640,
      elevationLoss: 710,
      startPoint: { lat: 47.742, lon: -3.367, ele: 60 },
      endPoint: { lat: 47.658, lon: -2.76, ele: 40 },
      geometry: [],
      label: null,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      selectedAccommodation: null,
      isRestDay: false,
    },
  ],
};

function mockValidShare(page: import("@playwright/test").Page) {
  return page.route(`**/s/${SHORT_CODE}`, (route, request) => {
    const accept = request.headers()["accept"] ?? "";
    if (!accept.includes("application/ld+json")) return route.fallback();
    return route.fulfill({
      status: 200,
      headers: { "Content-Type": "application/ld+json; charset=utf-8" },
      body: JSON.stringify(MOCK_SHARED_TRIP),
    });
  });
}

test.describe("/s/[code] page", () => {
  test("renders trip title, summary, timeline and read-only banner for a valid short code", async ({
    page,
  }) => {
    await mockValidShare(page);
    await page.goto(`/s/${SHORT_CODE}`);

    // Title
    await expect(
      page.getByRole("heading", { name: "Tour de Bretagne" }),
    ).toBeVisible({ timeout: 10000 });

    // Shared top bar with trip title and GPX download
    const topBar = page.getByTestId("shared-top-bar");
    await expect(topBar).toBeVisible();
    const topBarTitle = page.getByTestId("shared-top-bar-title");
    await expect(topBarTitle).toBeVisible();
    await expect(topBarTitle).toHaveText("Tour de Bretagne");
    await expect(
      topBar.getByRole("button", { name: "Télécharger le GPX complet" }),
    ).toBeVisible();

    // Trip summary stats
    await expect(page.getByTestId("total-distance")).toBeVisible();

    // Both stage cards visible
    await expect(page.getByTestId("stage-card-1")).toBeVisible();
    await expect(page.getByTestId("stage-card-2")).toBeVisible();

    // Read-only banner
    await expect(page.getByTestId("read-only-banner")).toBeVisible();
  });

  test("shows error state for an invalid short code", async ({ page }) => {
    await page.route(`**/s/invalid8`, (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      return route.fulfill({ status: 404, body: "" });
    });

    await page.goto(`/s/invalid8`);

    await expect(page.getByTestId("share-error")).toBeVisible({
      timeout: 10000,
    });

    // Top bar stays mounted in the error branch so the user has a home link
    await expect(page.getByTestId("shared-top-bar")).toBeVisible();

    // Back to home link
    await expect(page.getByRole("link").first()).toBeVisible();
  });

  test("stage cards are read-only (no delete or add buttons)", async ({
    page,
  }) => {
    await mockValidShare(page);
    await page.goto(`/s/${SHORT_CODE}`);

    await expect(page.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // No delete stage button in read-only mode
    await expect(page.getByTestId("delete-stage-button")).not.toBeVisible();

    // No add stage button in read-only mode
    await expect(page.getByTestId("add-stage-button")).not.toBeVisible();
  });
});
