import { test, expect } from "../fixtures/base.fixture";
import { mockAllApis } from "../fixtures/api-mocks";

/**
 * Tests for the offline consultation feature (issue #51).
 *
 * Covers:
 * - Saved trips section appears when trips are seeded in IndexedDB
 * - Clicking a saved trip card loads the trip into the planner
 * - No saved trips section when IndexedDB is empty
 */

const SEED_TRIP = {
  id: "offline-consult-trip-1",
  title: "Tour de l'Ardèche",
  sourceUrl: "https://www.komoot.com/fr-fr/tour/12345",
  totalDistance: 187000,
  totalElevation: 2850,
  totalElevationLoss: 2720,
  sourceType: "komoot",
  startDate: "2026-06-01",
  endDate: "2026-06-03",
  fatigueFactor: 0.8,
  elevationPenalty: 100,
  maxDistancePerDay: 80,
  averageSpeed: 15,
  ebikeMode: false,
  departureHour: 8,
  enabledAccommodationTypes: ["hotel", "camp_site"],
  stages: [
    {
      dayNumber: 1,
      distance: 72.5,
      elevation: 1180,
      elevationLoss: 920,
      startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
      endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
      geometry: [],
      label: null,
      startLabel: null,
      endLabel: null,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      accommodationSearchRadiusKm: 20,
      isRestDay: false,
      supplyTimeline: [],
    },
    {
      dayNumber: 2,
      distance: 63.2,
      elevation: 870,
      elevationLoss: 1050,
      startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
      endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
      geometry: [],
      label: null,
      startLabel: null,
      endLabel: null,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      accommodationSearchRadiusKm: 20,
      isRestDay: false,
      supplyTimeline: [],
    },
    {
      dayNumber: 3,
      distance: 51.6,
      elevation: 800,
      elevationLoss: 750,
      startPoint: { lat: 44.295, lon: 4.087, ele: 360 },
      endPoint: { lat: 44.112, lon: 3.876, ele: 410 },
      geometry: [],
      label: null,
      startLabel: null,
      endLabel: null,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      accommodationSearchRadiusKm: 20,
      isRestDay: false,
      supplyTimeline: [],
    },
  ],
  savedAt: new Date().toISOString(),
};

function seedIndexedDB(page: import("@playwright/test").Page) {
  return page.addInitScript((trip) => {
    const request = indexedDB.open("keyval-store", 1);
    request.onupgradeneeded = (e) => {
      const db = (e.target as IDBOpenDBRequest).result;
      if (!db.objectStoreNames.contains("keyval")) {
        db.createObjectStore("keyval");
      }
    };
    request.onsuccess = (e) => {
      const db = (e.target as IDBOpenDBRequest).result;
      const tx = db.transaction("keyval", "readwrite");
      tx.objectStore("keyval").put([trip], "offline_saved_trips");
    };
  }, SEED_TRIP);
}

test.describe("Offline consultation", () => {
  test.describe("Saved trips section", () => {
    test("saved trips section appears when trips are seeded in IndexedDB", async ({
      page,
    }) => {
      await seedIndexedDB(page);
      await mockAllApis(page);
      await page.goto("/");
      await page.waitForLoadState("networkidle");

      const section = page.getByTestId("saved-trips-section");
      await expect(section).toBeVisible({ timeout: 5000 });

      const card = page.getByTestId(`saved-trip-card-${SEED_TRIP.id}`);
      await expect(card).toBeVisible();
      await expect(card).toContainText(SEED_TRIP.title);
    });

    test("saved trip card shows distance and stage count", async ({ page }) => {
      await seedIndexedDB(page);
      await mockAllApis(page);
      await page.goto("/");
      await page.waitForLoadState("networkidle");

      const card = page.getByTestId(`saved-trip-card-${SEED_TRIP.id}`);
      await expect(card).toBeVisible({ timeout: 5000 });
      // 187000 m → 187 km
      await expect(card).toContainText("187 km");
      // 3 non-rest-day stages
      await expect(card).toContainText(/3 étape|3 stage/);
    });

    test("no saved trips section when IndexedDB is empty", async ({
      mockedPage,
    }) => {
      const section = mockedPage.getByTestId("saved-trips-section");
      await expect(section).not.toBeVisible();
    });
  });

  test.describe("Loading a saved trip", () => {
    test("clicking a saved trip card loads the trip into the planner", async ({
      page,
    }) => {
      await seedIndexedDB(page);
      await mockAllApis(page);
      await page.goto("/");
      await page.waitForLoadState("networkidle");

      const card = page.getByTestId(`saved-trip-card-${SEED_TRIP.id}`);
      await expect(card).toBeVisible({ timeout: 5000 });
      await card.click();

      // The trip title should now appear in the planner header
      const tripTitle = page.getByTestId("trip-title");
      await expect(tripTitle).toBeVisible({ timeout: 5000 });
      await expect(tripTitle).toContainText(SEED_TRIP.title);
    });

    test("loading a saved trip shows stages in read-only mode", async ({
      page,
    }) => {
      await seedIndexedDB(page);
      await mockAllApis(page);
      await page.goto("/");
      await page.waitForLoadState("networkidle");

      const card = page.getByTestId(`saved-trip-card-${SEED_TRIP.id}`);
      await expect(card).toBeVisible({ timeout: 5000 });
      await card.click();

      // Stage cards should be visible (3 non-rest stages in seed data)
      await expect(page.getByTestId("stage-card-1")).toBeVisible({
        timeout: 5000,
      });
    });
  });

  test.describe("Offline badge", () => {
    test("offline badge appears on saved trip cards when browser is offline", async ({
      page,
    }) => {
      await seedIndexedDB(page);
      await mockAllApis(page);
      await page.goto("/");
      await page.waitForLoadState("networkidle");

      // Go offline
      await page.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });

      const card = page.getByTestId(`saved-trip-card-${SEED_TRIP.id}`);
      await expect(card).toBeVisible({ timeout: 5000 });
      await expect(card).toContainText(/Hors ligne|Offline/);
    });
  });
});
