import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

const MOCK_TRIPS = {
  member: [
    {
      id: "01936f6e-0000-7000-8000-000000000101",
      title: "Tour des Alpes",
      totalDistance: 420.5,
      stageCount: 7,
      startDate: "2025-07-01T00:00:00+00:00",
      endDate: "2025-07-07T00:00:00+00:00",
      createdAt: "2025-06-01T00:00:00+00:00",
      updatedAt: "2025-06-01T00:00:00+00:00",
    },
    {
      id: "01936f6e-0000-7000-8000-000000000102",
      title: "Bretagne coastal",
      totalDistance: 310.2,
      stageCount: 5,
      startDate: "2025-08-01T00:00:00+00:00",
      endDate: "2025-08-05T00:00:00+00:00",
      createdAt: "2025-06-15T00:00:00+00:00",
      updatedAt: "2025-06-15T00:00:00+00:00",
    },
  ],
  totalItems: 2,
};

test.describe("/trips page", () => {
  test.beforeEach(async ({ page }) => {
    // Mock auth refresh so AuthGuard passes
    await page.route("**/auth/refresh", (route) =>
      route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
      }),
    );

    // Only intercept API calls (Accept: application/ld+json), not page navigation
    await page.route("**/trips*", (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      if (request.method() !== "GET") return route.fallback();

      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify(MOCK_TRIPS),
      });
    });
    await page.goto("/trips");
    await page.waitForLoadState("networkidle");
  });

  test("renders trip list with seeded trips", async ({ page }) => {
    await expect(page.getByText("Tour des Alpes")).toBeVisible();
    await expect(page.getByText("Bretagne coastal")).toBeVisible();
  });

  test("delete button opens confirmation dialog", async ({ page }) => {
    await expect(page.getByText("Tour des Alpes")).toBeVisible();
    const deleteButton = page
      .getByRole("button", {
        name: /supprimer le voyage/i,
      })
      .first();
    await expect(deleteButton).toBeVisible();
    await deleteButton.click();
    await expect(page.getByRole("dialog")).toBeVisible({ timeout: 5000 });
  });

  test("confirming delete removes trip from list", async ({ page }) => {
    await expect(page.getByText("Tour des Alpes")).toBeVisible();

    // Mock DELETE endpoint
    await page.route("**/trips/*", (route, request) => {
      if (request.method() === "DELETE") {
        return route.fulfill({ status: 204, body: "" });
      }
      return route.fallback();
    });

    // Open delete dialog
    const deleteButton = page
      .getByRole("button", {
        name: /supprimer le voyage/i,
      })
      .first();
    await deleteButton.click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible({ timeout: 5000 });

    // Stub refresh GET to return only the second trip
    await page.route("**/trips*", (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      if (request.method() !== "GET") return route.fallback();
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify({
          member: [MOCK_TRIPS.member[1]],
          totalItems: 1,
        }),
      });
    });

    // Confirm deletion
    await dialog.getByRole("button", { name: /supprimer/i }).click();
    // Wait for dialog to close before checking — the dialog description also contains the trip name
    await expect(dialog).not.toBeVisible({ timeout: 5000 });
    await expect(page.getByText("Tour des Alpes")).not.toBeVisible();
    await expect(page.getByText("Bretagne coastal")).toBeVisible();
  });

  test("pagination controls are hidden when totalItems is small", async ({
    page,
  }) => {
    // With only 2 items (< 20 per page), no pagination needed
    await expect(
      page.getByRole("button", { name: /page précédente/i }),
    ).not.toBeVisible();
    await expect(
      page.getByRole("button", { name: /page suivante/i }),
    ).not.toBeVisible();
  });
});

test.describe("/trips empty states", () => {
  test.beforeEach(async ({ page }) => {
    // Mock auth refresh so AuthGuard passes
    await page.route("**/auth/refresh", (route) =>
      route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
      }),
    );
  });

  test("shows empty state when no trips exist", async ({ page }) => {
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

    await page.goto("/trips");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("trips-empty-no-trips")).toBeVisible();
    await expect(
      page.getByTestId("trips-empty-new-trip-primary"),
    ).toBeVisible();
    // The no-results variant must NOT render in this case.
    await expect(page.getByTestId("trips-empty-no-results")).not.toBeVisible();
  });

  test("shows no-results state when filter has no match", async ({ page }) => {
    // Return populated trips when no title filter, empty otherwise.
    await page.route("**/trips*", (route, request) => {
      const accept = request.headers()["accept"] ?? "";
      if (!accept.includes("application/ld+json")) return route.fallback();
      if (request.method() !== "GET") return route.fallback();

      const url = new URL(request.url());
      const hasTitleFilter = url.searchParams.has("title");

      const body = hasTitleFilter
        ? { member: [], totalItems: 0 }
        : MOCK_TRIPS;

      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/ld+json; charset=utf-8" },
        body: JSON.stringify(body),
      });
    });

    await page.goto("/trips");
    await page.waitForLoadState("networkidle");

    // Initial state: trips visible.
    await expect(page.getByText("Tour des Alpes")).toBeVisible();
    await expect(page.getByText("Bretagne coastal")).toBeVisible();

    // Type a non-matching title in the search filter (350ms debounce).
    const searchInput = page.getByRole("searchbox", {
      name: /rechercher par titre/i,
    });
    await searchInput.fill("zzz-no-match");

    // No-results empty state appears.
    await expect(page.getByTestId("trips-empty-no-results")).toBeVisible();
    await expect(
      page.getByTestId("trips-empty-active-filters"),
    ).toBeVisible();

    // Click reset-filters → original trips reappear.
    await page.getByTestId("trips-empty-reset-filters").click();

    await expect(page.getByText("Tour des Alpes")).toBeVisible();
    await expect(page.getByText("Bretagne coastal")).toBeVisible();
    await expect(page.getByTestId("trips-empty-no-results")).not.toBeVisible();
  });
});
