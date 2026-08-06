import { test, expect } from "../fixtures/base.fixture";
import {
  nearbyPoiSearchResponse,
  nearbyPoiSuggestion,
} from "../fixtures/mock-data";

/**
 * Issue #935 — guided in-ride search.
 *
 * The free-text AI chat is replaced by a token-free, AI-free panel: the rider
 * taps one of eight question chips, geolocation resolves, the backend returns
 * nearby POIs and the thread fills with a recap + POI cards. Every visible
 * string is derived from next-intl; no server text is shown.
 */

const ALL_CATEGORIES = [
  "water",
  "shelter",
  "food",
  "resupply",
  "mechanic",
  "health",
  "train",
  "charging",
] as const;

async function openPanel(page: import("@playwright/test").Page) {
  const bubble = page.getByTestId("in-ride-bubble");
  await expect(bubble).toBeVisible({ timeout: 10_000 });
  await bubble.click();
  await expect(page.getByTestId("in-ride-panel")).toBeVisible();
}

test.describe("Guided in-ride search", () => {
  test.beforeEach(async ({ mockedPage }) => {
    await mockedPage
      .context()
      .grantPermissions(["geolocation"], { origin: "https://localhost" });
    await mockedPage
      .context()
      .setGeolocation({ latitude: 44.7, longitude: 4.6 });
  });

  test("bubble opens the panel with the eight chips and focuses the first", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await openPanel(mockedPage);

    await expect(mockedPage.getByTestId("in-ride-chips")).toBeVisible();
    for (const category of ALL_CATEGORIES) {
      await expect(
        mockedPage.getByTestId(`in-ride-chip-${category}`),
      ).toBeVisible();
    }
    // Focus lands on the first chip at open.
    await expect(mockedPage.getByTestId("in-ride-chip-water")).toBeFocused();
    // No free-text composer.
    await expect(mockedPage.locator("#in-ride-panel textarea")).toHaveCount(0);
  });

  test("tapping the water chip renders a recap and three POI cards", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await openPanel(mockedPage);

    await mockedPage.getByTestId("in-ride-chip-water").click();

    await expect(mockedPage.getByTestId("in-ride-recap")).toBeVisible();
    await expect(
      mockedPage.getByTestId("in-ride-pois").getByTestId("poi-card"),
    ).toHaveCount(3);
    await expect(mockedPage.getByTestId("in-ride-disclaimer")).toBeVisible();
  });

  test("each chip triggers its own category", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await openPanel(mockedPage);

    for (const category of ALL_CATEGORIES) {
      const [request] = await Promise.all([
        mockedPage.waitForRequest(
          (r) => r.url().includes("/nearby-pois") && r.method() === "POST",
        ),
        mockedPage.getByTestId(`in-ride-chip-${category}`).click(),
      ]);
      expect(request.postDataJSON().category).toBe(category);
      await expect(
        mockedPage
          .getByTestId("in-ride-pois")
          .last()
          .getByTestId("poi-card")
          .first(),
      ).toHaveAttribute("data-category", category);
    }
  });

  test("the whole POI card is the map tap target; the phone stays separate", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await openPanel(mockedPage);
    await mockedPage.getByTestId("in-ride-chip-water").click();

    const card = mockedPage.getByTestId("poi-card").first();
    const mapLink = card.getByTestId("poi-card-open-maps");
    // The map link is stretched over the whole card via an inset ::after.
    await expect(mapLink).toHaveClass(/after:inset-0/);
    await expect(mapLink).toHaveAttribute("href", /google\.com\/maps/);
  });

  test("phone control is reachable outside the stretched link", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await mockedPage
      .context()
      .setGeolocation({ latitude: 44.7, longitude: 4.6 });
    await mockedPage.route("**/trips/*/nearby-pois", (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify(
          nearbyPoiSearchResponse({
            category: "mechanic",
            totalFound: 1,
            pois: [
              nearbyPoiSuggestion({
                category: "mechanic",
                name: "Vélo Atelier",
                phone: "+33 4 75 00 00 00",
              }),
            ],
          }),
        ),
      }),
    );
    await openPanel(mockedPage);
    await mockedPage.getByTestId("in-ride-chip-mechanic").click();

    // Scope to the POI card: the trip page carries other `tel:` links (accommodation
    // contacts), so an unscoped locator would match more than one element.
    const card = mockedPage.getByTestId("poi-card").first();
    const tel = card.locator('a[href^="tel:"]');
    await expect(tel).toBeVisible();
    await expect(tel).toHaveClass(/z-10/);
  });

  test("widen doubles the radius and replays", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await openPanel(mockedPage);
    await mockedPage.getByTestId("in-ride-chip-water").click();

    const widen = mockedPage.getByTestId("in-ride-widen");
    await expect(widen).toBeVisible();

    const [request] = await Promise.all([
      mockedPage.waitForRequest(
        (r) => r.url().includes("/nearby-pois") && r.method() === "POST",
      ),
      widen.click(),
    ]);
    // Default response radius is 3000 m → widen sends 6000 m.
    expect(request.postDataJSON().radiusMeters).toBe(6000);
  });

  test("detour badge only shows for a positive detour", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await mockedPage
      .context()
      .setGeolocation({ latitude: 44.7, longitude: 4.6 });
    await mockedPage.route("**/trips/*/nearby-pois", (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify(
          nearbyPoiSearchResponse({
            category: "water",
            totalFound: 2,
            pois: [
              nearbyPoiSuggestion({ name: "Avec détour", detour_m: 240 }),
              nearbyPoiSuggestion({ name: "Sans détour", detour_m: null }),
            ],
          }),
        ),
      }),
    );
    await openPanel(mockedPage);
    await mockedPage.getByTestId("in-ride-chip-water").click();

    await expect(mockedPage.getByTestId("poi-card-detour")).toHaveCount(1);
  });
});

test.describe("Recap states", () => {
  test.beforeEach(async ({ mockedPage }) => {
    await mockedPage
      .context()
      .grantPermissions(["geolocation"], { origin: "https://localhost" });
    await mockedPage
      .context()
      .setGeolocation({ latitude: 44.7, longitude: 4.6 });
  });

  test.describe("truncated results", () => {
    test.use({
      mockOptions: {
        nearbyPoiResults: nearbyPoiSearchResponse({ totalFound: 12 }),
      },
    });
    test("shows the truncated recap and the cards", async ({
      createFullTrip,
      mockedPage,
    }) => {
      await createFullTrip();
      await openPanel(mockedPage);
      await mockedPage.getByTestId("in-ride-chip-water").click();
      await expect(
        mockedPage.getByTestId("in-ride-pois").getByTestId("poi-card"),
      ).toHaveCount(3);
      await expect(mockedPage.getByTestId("in-ride-recap")).toBeVisible();
    });
  });

  test.describe("no results", () => {
    test.use({
      mockOptions: {
        nearbyPoiResults: nearbyPoiSearchResponse({ totalFound: 0, pois: [] }),
      },
    });
    test("shows the empty recap and no cards", async ({
      createFullTrip,
      mockedPage,
    }) => {
      await createFullTrip();
      await openPanel(mockedPage);
      await mockedPage.getByTestId("in-ride-chip-water").click();
      await expect(mockedPage.getByTestId("in-ride-recap")).toBeVisible();
      await expect(mockedPage.getByTestId("in-ride-pois")).toHaveCount(0);
    });
  });

  test.describe("cap reached", () => {
    test.use({
      mockOptions: {
        nearbyPoiResults: nearbyPoiSearchResponse({
          totalFound: 200,
          capReached: true,
          pois: [],
        }),
      },
    });
    test("shows the cap-reached recap and hides widen", async ({
      createFullTrip,
      mockedPage,
    }) => {
      await createFullTrip();
      await openPanel(mockedPage);
      await mockedPage.getByTestId("in-ride-chip-water").click();
      await expect(mockedPage.getByTestId("in-ride-recap")).toBeVisible();
      await expect(mockedPage.getByTestId("in-ride-widen")).toHaveCount(0);
    });
  });

  test.describe("out of coverage", () => {
    test.use({
      mockOptions: {
        nearbyPoiResults: nearbyPoiSearchResponse({
          totalFound: 0,
          outOfCoverage: true,
          pois: [],
        }),
      },
    });
    test("shows the out-of-coverage recap", async ({
      createFullTrip,
      mockedPage,
    }) => {
      await createFullTrip();
      await openPanel(mockedPage);
      await mockedPage.getByTestId("in-ride-chip-water").click();
      await expect(mockedPage.getByTestId("in-ride-recap")).toBeVisible();
      await expect(mockedPage.getByTestId("in-ride-pois")).toHaveCount(0);
    });
  });

  test.describe("rate limited", () => {
    test.use({ mockOptions: { nearbyPoiResults: 429 } });
    test("surfaces the rate-limit error", async ({
      createFullTrip,
      mockedPage,
    }) => {
      await createFullTrip();
      await openPanel(mockedPage);
      await mockedPage.getByTestId("in-ride-chip-water").click();
      await expect(mockedPage.getByRole("alert")).toBeVisible();
    });
  });
});
