import { test, expect } from "../fixtures/base.fixture";

function tripsResponse(
  trips: Record<string, unknown>[],
  totalItems: number,
): string {
  return JSON.stringify({
    "@context": "/contexts/Trip",
    "@id": "/trips",
    "@type": "hydra:Collection",
    "hydra:totalItems": totalItems,
    "hydra:member": trips,
    member: trips,
    totalItems,
  });
}

test.describe("Home recent trips widget", () => {
  test("recent trips section is hidden when there are no trips", async ({
    mockedPage,
  }) => {
    // Default mockAllApis returns 0 trips for GET /trips
    // Wait for loading to complete — spinner disappears when fetch resolves
    await expect(
      mockedPage.getByTestId("recent-trips-loading"),
    ).not.toBeVisible({ timeout: 5000 });
    await expect(mockedPage.getByTestId("recent-trips")).not.toBeVisible();
  });

  test("recent trips section appears when trips exist", async ({
    mockedPage,
  }) => {
    // Override GET /trips to return one trip (LIFO: runs before mockAllApis handler)
    await mockedPage.route(
      (url) => url.pathname === "/trips",
      async (route, request) => {
        if (request.method() !== "GET") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/ld+json",
          body: tripsResponse(
            [
              {
                "@type": "Trip",
                id: "recent-trip-abc",
                title: "Tour de l'Ardèche",
                totalDistance: 187000,
                startDate: null,
                endDate: null,
              },
            ],
            1,
          ),
        });
      },
    );

    // Reload page so RecentTrips fetches with the new mock
    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");

    await expect(mockedPage.getByTestId("recent-trips")).toBeVisible({
      timeout: 5000,
    });
    await expect(
      mockedPage.getByTestId("recent-trip-recent-trip-abc"),
    ).toBeVisible();
    await expect(
      mockedPage.getByTestId("recent-trip-recent-trip-abc"),
    ).toContainText("Tour de l'Ardèche");
  });

  test("view all link shows trip count", async ({ mockedPage }) => {
    await mockedPage.route(
      (url) => url.pathname === "/trips",
      async (route, request) => {
        if (request.method() !== "GET") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/ld+json",
          body: tripsResponse(
            [
              {
                "@type": "Trip",
                id: "trip-1",
                title: "Mon voyage",
                totalDistance: 100000,
                startDate: null,
                endDate: null,
              },
            ],
            42,
          ),
        });
      },
    );

    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");

    await expect(mockedPage.getByTestId("recent-trips-view-all")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("recent-trips-view-all")).toContainText(
      "42",
    );
  });

  test("clicking a recent trip navigates to trip detail", async ({
    mockedPage,
  }) => {
    await mockedPage.route(
      (url) => url.pathname === "/trips",
      async (route, request) => {
        if (request.method() !== "GET") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/ld+json",
          body: tripsResponse(
            [
              {
                "@type": "Trip",
                id: "nav-trip-xyz",
                title: "Voyage cliquable",
                totalDistance: 50000,
                startDate: null,
                endDate: null,
              },
            ],
            1,
          ),
        });
      },
    );

    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");

    await expect(
      mockedPage.getByTestId("recent-trip-nav-trip-xyz"),
    ).toBeVisible({ timeout: 5000 });
    await mockedPage.getByTestId("recent-trip-nav-trip-xyz").click();
    await expect(mockedPage).toHaveURL(/\/trips\/nav-trip-xyz/, {
      timeout: 5000,
    });
  });
});
