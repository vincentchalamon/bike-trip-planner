import { test, expect } from "@playwright/test";

const TRIP_ID = "share-uuid-abc-999";
const SHARE_TOKEN = "share-token-xyz-valid";

const MOCK_SHARED_TRIP = {
  "@context": "/contexts/TripShare",
  "@type": "TripDetail",
  id: TRIP_ID,
  title: "Tour de Bretagne",
  startDate: "2026-07-01T00:00:00+00:00",
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
  return page.route(
    `**/shares/${TRIP_ID}?token=${SHARE_TOKEN}`,
    (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify(MOCK_SHARED_TRIP),
      });
    },
  );
}

test.describe("/shares/[tripId] page", () => {
  test("renders trip title, summary, timeline and read-only banner for a valid share token", async ({
    page,
  }) => {
    await mockValidShare(page);
    await page.goto(`/shares/${TRIP_ID}?token=${SHARE_TOKEN}`);

    // Title
    await expect(
      page.getByRole("heading", { name: "Tour de Bretagne" }),
    ).toBeVisible({ timeout: 10000 });

    // Trip summary stats
    await expect(page.getByTestId("total-distance")).toBeVisible();

    // Both stage cards visible
    await expect(page.getByTestId("stage-card-1")).toBeVisible();
    await expect(page.getByTestId("stage-card-2")).toBeVisible();

    // Read-only banner
    await expect(page.getByTestId("read-only-banner")).toBeVisible();
  });

  test("shows error state for an invalid or expired share token", async ({
    page,
  }) => {
    await page.route(`**/shares/${TRIP_ID}**`, (route) =>
      route.fulfill({ status: 404, body: "" }),
    );

    await page.goto(`/shares/${TRIP_ID}?token=invalid-token`);

    await expect(page.getByTestId("share-error")).toBeVisible({
      timeout: 10000,
    });

    // Back to home link
    await expect(page.getByRole("link").first()).toBeVisible();
  });

  test("shows error state when token is missing from URL", async ({ page }) => {
    await page.goto(`/shares/${TRIP_ID}`);

    await expect(page.getByTestId("share-error")).toBeVisible({
      timeout: 10000,
    });
  });

  test("stage cards are read-only (no delete or add buttons)", async ({
    page,
  }) => {
    await mockValidShare(page);
    await page.goto(`/shares/${TRIP_ID}?token=${SHARE_TOKEN}`);

    await expect(page.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // No delete stage button in read-only mode
    await expect(page.getByTestId("delete-stage-button")).not.toBeVisible();

    // No add stage button in read-only mode
    await expect(page.getByTestId("add-stage-button")).not.toBeVisible();
  });
});
