import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

const TRIP_ID = "01936f6e-0000-7000-8000-000000000101";

const MOCK_STAGES = [
  {
    dayNumber: 1,
    distance: 72.5,
    elevation: 1180,
    elevationLoss: 920,
    startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
    endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
    geometry: [
      { lat: 44.735, lon: 4.598, ele: 280 },
      { lat: 44.532, lon: 4.392, ele: 540 },
    ],
    label: null,
    isRestDay: false,
    weather: {
      icon: "02d",
      description: "Partly cloudy",
      tempMin: 14,
      tempMax: 26,
      windSpeed: 12,
      windDirection: "NO",
      precipitationProbability: 10,
      humidity: 65,
      comfortIndex: 78,
      relativeWindDirection: "crosswind",
    },
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
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
      { lat: 44.295, lon: 4.087, ele: 360 },
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
    dayNumber: 3,
    distance: 51.6,
    elevation: 800,
    elevationLoss: 750,
    startPoint: { lat: 44.295, lon: 4.087, ele: 360 },
    endPoint: { lat: 44.112, lon: 3.876, ele: 410 },
    geometry: [
      { lat: 44.295, lon: 4.087, ele: 360 },
      { lat: 44.112, lon: 3.876, ele: 410 },
    ],
    label: null,
    isRestDay: false,
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
  },
];

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
  test.beforeEach(async ({ page }) => {
    await page.route("**/auth/refresh", (route) =>
      route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
      }),
    );
  });

  test("renders the trip actions toolbar after successful load", async ({
    page,
  }) => {
    await page.route(`**/trips/${TRIP_ID}/detail`, (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      // A loaded trip (status ready + structural stages) mounts the trip view
      // directly under the synchronous flow (ADR-043); a draft + empty payload
      // would stay on the single loader and never render the actions toolbar.
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify({
          ...MOCK_DETAIL,
          status: "ready",
          stages: MOCK_STAGES,
        }),
      });
    });

    await page.goto(`/trips/${TRIP_ID}`);
    // The dedicated close button was removed (recette #649); the per-trip
    // actions toolbar (config gear) sits next to the title once loaded.
    // TripPlanner may keep SSE connections open — do not wait for networkidle.
    await expect(page.getByTestId("trip-actions")).toBeVisible({
      timeout: 10000,
    });
    await expect(page.getByTestId("config-open-button")).toBeVisible();
  });

  test("shows error state when API call fails", async ({ page }) => {
    await page.route(`**/trips/${TRIP_ID}/detail`, (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      return route.fulfill({ status: 500, body: "" });
    });

    await page.goto(`/trips/${TRIP_ID}`);
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("trip-not-found-page")).toBeVisible({
      timeout: 5000,
    });
    await expect(page.getByTestId("trip-not-found-back")).toBeVisible();
  });
});

test.describe("Horizontal day timeline", () => {
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
        body: JSON.stringify({ ...MOCK_DETAIL, stages: MOCK_STAGES }),
      });
    });
  });

  test("clicking a day on the horizontal timeline activates that stage", async ({
    page,
  }) => {
    await page.goto(`/trips/${TRIP_ID}`);

    // Wait for the roadbook master-detail view to be visible. The vertical
    // sidebar was replaced by the horizontal progress bar (recette #649).
    await expect(page.getByTestId("roadbook-master-detail")).toBeVisible({
      timeout: 10000,
    });
    await expect(page.getByTestId("stage-progress-bar")).toBeVisible();

    // Stage 1 (day 1) is selected by default; click day 2 on the timeline.
    await page.getByTestId("progress-segment-2").click();

    // The day-2 marker should now be the current one.
    await expect(page.getByTestId("progress-segment-2")).toHaveAttribute(
      "aria-current",
      "true",
    );

    // The detail panel stays visible.
    await expect(page.getByTestId("stage-detail-panel")).toBeVisible();
  });
});
