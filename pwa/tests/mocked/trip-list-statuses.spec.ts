import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

const MOCK_TRIPS_WITH_STATUSES = {
  member: [
    {
      id: "01936f6e-0000-7000-8000-000000000201",
      title: "Brouillon Alpes",
      totalDistance: 0,
      stageCount: 0,
      startDate: null,
      endDate: null,
      createdAt: "2025-06-01T00:00:00+00:00",
      updatedAt: "2025-06-01T00:00:00+00:00",
      status: "draft",
    },
    {
      id: "01936f6e-0000-7000-8000-000000000202",
      title: "Analyse Bretagne",
      totalDistance: 0,
      stageCount: 0,
      startDate: "2025-08-01T00:00:00+00:00",
      endDate: "2025-08-05T00:00:00+00:00",
      createdAt: "2025-06-15T00:00:00+00:00",
      updatedAt: "2025-06-15T00:00:00+00:00",
      status: "analyzing",
    },
    {
      id: "01936f6e-0000-7000-8000-000000000203",
      title: "Voyage Pyrénées",
      totalDistance: 520.0,
      stageCount: 8,
      startDate: "2025-09-01T00:00:00+00:00",
      endDate: "2025-09-08T00:00:00+00:00",
      createdAt: "2025-07-01T00:00:00+00:00",
      updatedAt: "2025-07-01T00:00:00+00:00",
      status: "analyzed",
    },
  ],
  totalItems: 3,
};

test.describe("trip list statuses", () => {
  test.beforeEach(async ({ page }) => {
    // Mock auth refresh so AuthGuard passes
    await page.route("**/auth/refresh", (route) =>
      route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
      }),
    );

    // Mock the trips collection API
    await page.route("**/trips*", (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      if (request.method() !== "GET") return route.fallback();

      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify(MOCK_TRIPS_WITH_STATUSES),
      });
    });

    await page.goto("/trips");
    await page.waitForLoadState("networkidle");
  });

  test("displays draft status badge for unanalysed trip", async ({ page }) => {
    await expect(page.getByText("Brouillon Alpes")).toBeVisible();
    const draftBadge = page.getByTestId("status-draft").first();
    await expect(draftBadge).toBeVisible();
  });

  test("displays analyzing status badge for trip in progress", async ({
    page,
  }) => {
    await expect(page.getByText("Analyse Bretagne")).toBeVisible();
    const analyzingBadge = page.getByTestId("status-analyzing").first();
    await expect(analyzingBadge).toBeVisible();
  });

  test("displays analyzed status badge for completed trip", async ({ page }) => {
    await expect(page.getByText("Voyage Pyrénées")).toBeVisible();
    const analyzedBadge = page.getByTestId("status-analyzed").first();
    await expect(analyzedBadge).toBeVisible();
  });

  test("all three status badges are visible simultaneously", async ({
    page,
  }) => {
    await expect(page.getByTestId("status-draft").first()).toBeVisible();
    await expect(page.getByTestId("status-analyzing").first()).toBeVisible();
    await expect(page.getByTestId("status-analyzed").first()).toBeVisible();
  });

  test("clicking draft trip navigates to trip detail", async ({ page }) => {
    // Mock the trip detail endpoint so navigation doesn't fail
    await page.route(
      "**/trips/01936f6e-0000-7000-8000-000000000201/detail",
      (route) =>
        route.fulfill({
          status: 200,
          headers: { "Content-Type": "application/ld+json; charset=utf-8" },
          body: JSON.stringify({
            id: "01936f6e-0000-7000-8000-000000000201",
            title: "Brouillon Alpes",
            stages: [],
            isLocked: false,
            fatigueFactor: 0.9,
            elevationPenalty: 50,
            maxDistancePerDay: 80,
            averageSpeed: 15,
            ebikeMode: false,
            departureHour: 8,
            enabledAccommodationTypes: [],
          }),
        }),
    );

    await page
      .getByTestId("trip-item-01936f6e-0000-7000-8000-000000000201")
      .click();
    await expect(page).toHaveURL(
      /\/trips\/01936f6e-0000-7000-8000-000000000201/,
      { timeout: 5000 },
    );
  });

  test("clicking analyzed trip navigates to trip detail", async ({ page }) => {
    await page.route(
      "**/trips/01936f6e-0000-7000-8000-000000000203/detail",
      (route) =>
        route.fulfill({
          status: 200,
          headers: { "Content-Type": "application/ld+json; charset=utf-8" },
          body: JSON.stringify({
            id: "01936f6e-0000-7000-8000-000000000203",
            title: "Voyage Pyrénées",
            stages: [],
            isLocked: false,
            fatigueFactor: 0.9,
            elevationPenalty: 50,
            maxDistancePerDay: 80,
            averageSpeed: 15,
            ebikeMode: false,
            departureHour: 8,
            enabledAccommodationTypes: [],
          }),
        }),
    );

    await page
      .getByTestId("trip-item-01936f6e-0000-7000-8000-000000000203")
      .click();
    await expect(page).toHaveURL(
      /\/trips\/01936f6e-0000-7000-8000-000000000203/,
      { timeout: 5000 },
    );
  });

  test("new trip button is visible and links to trip creation", async ({
    page,
  }) => {
    const newTripButton = page.getByTestId("new-trip-button");
    await expect(newTripButton).toBeVisible();
    await newTripButton.click();
    await expect(page).toHaveURL(/\/trips\/new/, { timeout: 5000 });
  });
});

test.describe("mes voyages header link", () => {
  test("my trips link is visible when authenticated", async ({ page }) => {
    // Mock auth so the user is logged in
    await page.route("**/auth/refresh", (route) =>
      route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
      }),
    );

    // Mock trips endpoint (no trips needed)
    await page.route("**/trips*", (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      if (request.method() !== "GET") return route.fallback();
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify({ member: [], totalItems: 0 }),
      });
    });

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // The "Mes voyages" link is shown in the TripPlanner header when authenticated and no trip open
    const myTripsLink = page.getByTestId("my-trips-link");
    await expect(myTripsLink).toBeVisible({ timeout: 5000 });
  });

  test("my trips link is not visible when unauthenticated", async ({
    page,
  }) => {
    // Mock auth to fail (unauthenticated)
    await page.route("**/auth/refresh", (route) =>
      route.fulfill({
        status: 401,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ message: "Unauthorized" }),
      }),
    );

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // Unauthenticated users see the landing page — no "Mes voyages" link
    const myTripsLink = page.getByTestId("my-trips-link");
    await expect(myTripsLink).not.toBeVisible({ timeout: 3000 });
  });
});
