import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN, mockAllApis } from "../fixtures/api-mocks";
import { expandLinkCard } from "../fixtures/base.fixture";

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

  test("shows not found immediately on a 404 from a direct visit (recette #649)", async ({
    page,
  }) => {
    // A 404 on a trip we did NOT just create (empty store — a foreign or missing
    // trip, object-level authz hidden as 404 per ADR-038) must surface the error
    // immediately, without retrying (a direct visit / reload never owns a fresh
    // trip in the store).
    await page.route(`**/trips/${TRIP_ID}/detail`, (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      return route.fulfill({ status: 404, body: "" });
    });

    await page.goto(`/trips/${TRIP_ID}`);

    await expect(page.getByTestId("trip-not-found-page")).toBeVisible({
      timeout: 5000,
    });
  });

  test("shows not found when the detail payload is not valid JSON (recette #649)", async ({
    page,
  }) => {
    // A 200 with a non-JSON body (proxy / CDN HTML error page) must surface the
    // error instead of hanging the loader — res.json() would otherwise reject.
    await page.route(`**/trips/${TRIP_ID}/detail`, (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "text/html; charset=utf-8" },
        body: "<html><body>Bad gateway</body></html>",
      });
    });

    await page.goto(`/trips/${TRIP_ID}`);

    await expect(page.getByTestId("trip-not-found-page")).toBeVisible({
      timeout: 5000,
    });
  });

  test("re-syncs a draft trip via /detail when the stages event is missed (recette #649)", async ({
    page,
  }) => {
    // First load is a draft with no stages (single loader). No Mercure event is
    // injected, so only the /detail re-sync can surface the computed trip —
    // this is the "had to reload the page" symptom.
    let calls = 0;
    await page.route(`**/trips/${TRIP_ID}/detail`, (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      calls += 1;
      const ready = calls > 1;
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify({
          ...MOCK_DETAIL,
          status: ready ? "ready" : "draft",
          stages: ready ? MOCK_STAGES : [],
        }),
      });
    });

    await page.goto(`/trips/${TRIP_ID}`);

    await expect(page.getByTestId("trip-actions")).toBeVisible({
      timeout: 10000,
    });
  });

  test("retries 404s on our freshly-created trip then shows not found (recette #649)", async ({
    page,
  }) => {
    // Full creation flow → ownsFreshTrip === true (the trip is in the store via
    // setTrip before the router.push). The detail endpoint always 404s, so the
    // loader must exhaust its retries and surface "Voyage introuvable" rather
    // than spin forever.
    await mockAllApis(page, { postTripBody: { id: TRIP_ID, isLocked: false } });
    await page.route(`**/trips/${TRIP_ID}/detail`, (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      return route.fulfill({ status: 404, body: "" });
    });

    await page.goto("/");
    await expandLinkCard(page);
    const input = page.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");

    await page.waitForURL(new RegExp(`/trips/${TRIP_ID}`), { timeout: 5000 });
    // 5 retries × 1200 ms + navigation overhead.
    await expect(page.getByTestId("trip-not-found-page")).toBeVisible({
      timeout: 12000,
    });
  });
});
