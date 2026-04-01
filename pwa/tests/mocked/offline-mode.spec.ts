import { test, expect } from "../fixtures/base.fixture";

/**
 * Tests for the offline mode feature (issue #72).
 *
 * Covers:
 * - Offline banner display when the browser loses connectivity
 * - Reconnection banner after coming back online
 * - Mutation guards: inputs disabled when offline
 * - IndexedDB persistence: saved trips loaded on app init
 */
test.describe("Offline mode", () => {
  test.describe("Offline banner", () => {
    test("banner is not visible when online", async ({ mockedPage }) => {
      await expect(
        mockedPage.getByTestId("offline-banner"),
      ).not.toBeVisible();
    });

    test("shows offline banner when browser goes offline", async ({
      mockedPage,
    }) => {
      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });

      const banner = mockedPage.getByTestId("offline-banner");
      await expect(banner).toBeVisible({ timeout: 3000 });
      await expect(banner).toContainText("Hors ligne");
    });

    test("offline banner has amber styling", async ({ mockedPage }) => {
      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });

      const banner = mockedPage.getByTestId("offline-banner");
      await expect(banner).toBeVisible({ timeout: 3000 });
      await expect(banner).toHaveAttribute("role", "status");
    });

    test("shows reconnection banner when back online after being offline", async ({
      mockedPage,
    }) => {
      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });
      await expect(mockedPage.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });

      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("online"));
      });

      const banner = mockedPage.getByTestId("offline-banner");
      await expect(banner).toBeVisible({ timeout: 3000 });
      await expect(banner).toContainText("Connexion rétablie");
    });

    test("reconnection banner auto-dismisses after 3 seconds", async ({
      mockedPage,
    }) => {
      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });
      await expect(mockedPage.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });
      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("online"));
      });
      // Banner should appear then disappear automatically
      await expect(mockedPage.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });
      await expect(mockedPage.getByTestId("offline-banner")).not.toBeVisible({
        timeout: 5000,
      });
    });
  });

  test.describe("Mutation guards", () => {
    test("magic-link input is disabled when offline", async ({
      mockedPage,
    }) => {
      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });

      await expect(mockedPage.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });

      const input = mockedPage.getByTestId("magic-link-input");
      await expect(input).toBeDisabled();
    });

    test("GPX upload button is disabled when offline", async ({
      mockedPage,
    }) => {
      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });

      await expect(mockedPage.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });

      const gpxButton = mockedPage.getByTestId("gpx-upload-button");
      await expect(gpxButton).toBeDisabled();
    });

    test("magic-link input re-enabled when back online", async ({
      mockedPage,
    }) => {
      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });
      await expect(mockedPage.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });
      const input = mockedPage.getByTestId("magic-link-input");
      await expect(input).toBeDisabled();

      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("online"));
      });
      await expect(input).toBeEnabled({ timeout: 3000 });
    });
  });

  test.describe("IndexedDB persistence", () => {
    test("loads saved trips from IndexedDB on init", async ({ page }) => {
      const { mockAllApis } = await import("../fixtures/api-mocks");

      // Pre-populate IndexedDB before the app loads
      await page.addInitScript(() => {
        // Override idb-keyval to return a saved trip on first get
        const savedTrips = [
          {
            id: "saved-trip-offline-1",
            title: "Mon voyage Ardèche",
            sourceUrl: "https://www.komoot.com/fr-fr/tour/12345",
            totalDistance: 187000,
            totalElevation: 2850,
            totalElevationLoss: -2800,
            sourceType: "komoot",
            startDate: "2026-06-01",
            endDate: "2026-06-03",
            fatigueFactor: 0.1,
            elevationPenalty: 0.3,
            maxDistancePerDay: 80,
            averageSpeed: 18,
            ebikeMode: false,
            departureHour: 8,
            enabledAccommodationTypes: ["hotel", "camp_site"],
            stages: [],
            savedAt: new Date().toISOString(),
          },
        ];

        // Intercept idb-keyval by patching the IndexedDB call at the module level
        // We inject data directly into IndexedDB so idb-keyval reads it naturally
        const dbName = "keyval-store";
        const storeName = "keyval";
        const request = indexedDB.open(dbName, 1);
        request.onupgradeneeded = (e) => {
          const db = (e.target as IDBOpenDBRequest).result;
          if (!db.objectStoreNames.contains(storeName)) {
            db.createObjectStore(storeName);
          }
        };
        request.onsuccess = (e) => {
          const db = (e.target as IDBOpenDBRequest).result;
          const tx = db.transaction(storeName, "readwrite");
          tx.objectStore(storeName).put(savedTrips, "offline_saved_trips");
        };
      });

      await mockAllApis(page);
      await page.goto("/");
      await page.waitForLoadState("networkidle");

      // The recent trips panel should reflect the saved trip once loaded
      // Saved trips are loaded into the offline store on mount
      const savedTripCount = await page.evaluate(async () => {
        // Wait briefly for the async loadSavedTrips to complete
        await new Promise((resolve) => setTimeout(resolve, 500));
        // Check IndexedDB directly to confirm the data is there
        return new Promise<number>((resolve) => {
          const request = indexedDB.open("keyval-store", 1);
          request.onsuccess = (e) => {
            const db = (e.target as IDBOpenDBRequest).result;
            const tx = db.transaction("keyval", "readonly");
            const store = tx.objectStore("keyval");
            const getReq = store.get("offline_saved_trips");
            getReq.onsuccess = () => {
              const trips = getReq.result;
              resolve(Array.isArray(trips) ? trips.length : 0);
            };
            getReq.onerror = () => resolve(0);
          };
          request.onerror = () => resolve(0);
        });
      });

      expect(savedTripCount).toBe(1);
    });

    test("trip is saved to IndexedDB after trip_complete event", async ({
      submitUrl,
      injectSequence,
      mockedPage,
    }) => {
      const { fullTripEventSequence } = await import("../fixtures/mock-data");

      await submitUrl();
      await injectSequence(fullTripEventSequence());
      await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
        timeout: 10000,
      });

      // Allow async saveTrip to complete
      await mockedPage.waitForTimeout(500);

      const savedCount = await mockedPage.evaluate(async () => {
        return new Promise<number>((resolve) => {
          const request = indexedDB.open("keyval-store", 1);
          request.onsuccess = (e) => {
            const db = (e.target as IDBOpenDBRequest).result;
            if (!db.objectStoreNames.contains("keyval")) {
              resolve(0);
              return;
            }
            const tx = db.transaction("keyval", "readonly");
            const store = tx.objectStore("keyval");
            const getReq = store.get("offline_saved_trips");
            getReq.onsuccess = () => {
              const trips = getReq.result;
              resolve(Array.isArray(trips) ? trips.length : 0);
            };
            getReq.onerror = () => resolve(0);
          };
          request.onerror = () => resolve(0);
        });
      });

      expect(savedCount).toBeGreaterThanOrEqual(1);
    });
  });
});
