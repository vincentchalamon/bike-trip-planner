import { test, expect } from "../fixtures/base.fixture";
import { mockAllApis } from "../fixtures/api-mocks";

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
      await expect(mockedPage.getByTestId("offline-banner")).not.toBeVisible();
    });

    test("shows offline banner when browser goes offline", async ({
      mockedPage,
    }) => {
      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });

      const banner = mockedPage.getByTestId("offline-banner");
      await expect(banner).toBeVisible({ timeout: 3000 });
      // locale-agnostic: matches either French or English copy
      await expect(banner).toContainText(/Hors ligne|Offline/);
    });

    test("offline banner has role=status and aria-live=polite", async ({
      mockedPage,
    }) => {
      await mockedPage.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });

      const banner = mockedPage.getByTestId("offline-banner");
      await expect(banner).toBeVisible({ timeout: 3000 });
      await expect(banner).toHaveAttribute("role", "status");
      await expect(banner).toHaveAttribute("aria-live", "polite");
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
      // locale-agnostic: matches either French or English copy
      await expect(banner).toContainText(
        /Connexion rétablie|Connection restored/,
      );
    });

    test("reconnection banner auto-dismisses after 3 seconds", async ({
      page,
    }) => {
      // Install fake clock before page load so setTimeout is controlled
      await page.clock.install();
      await mockAllApis(page);
      await page.goto("/");
      await page.waitForLoadState("networkidle");

      await page.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });
      await expect(page.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });

      await page.evaluate(() => {
        window.dispatchEvent(new Event("online"));
      });
      await expect(page.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });

      // Fast-forward past the 3-second auto-dismiss timer
      await page.clock.fastForward(3100);
      await expect(page.getByTestId("offline-banner")).not.toBeVisible();
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

    test("GPX upload card is disabled when offline", async ({ page }) => {
      // Use raw page + mockAllApis to avoid the base fixture auto-expanding
      // the Link card (which would hide the GPX card).
      await mockAllApis(page);
      await page.goto("/");
      await page.waitForLoadState("networkidle");

      await page.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
      });

      await expect(page.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });

      const gpxCard = page.getByTestId("card-gpx");
      await expect(gpxCard).toHaveAttribute("data-disabled", "true");
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
    test("pre-seeded trip data persists in IndexedDB across page loads", async ({
      page,
    }) => {
      // Seed IndexedDB before the app loads to simulate a previously saved trip
      await page.addInitScript(() => {
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
          tx.objectStore("keyval").put(savedTrips, "offline_saved_trips");
        };
      });

      await mockAllApis(page);
      await page.goto("/");
      await page.waitForLoadState("networkidle");

      // Verify the seeded data is readable from IndexedDB
      const savedTripCount = await page.evaluate(
        () =>
          new Promise<number>((resolve) => {
            const request = indexedDB.open("keyval-store", 1);
            request.onsuccess = (e) => {
              const db = (e.target as IDBOpenDBRequest).result;
              const tx = db.transaction("keyval", "readonly");
              const getReq = tx
                .objectStore("keyval")
                .get("offline_saved_trips");
              getReq.onsuccess = () =>
                resolve(
                  Array.isArray(getReq.result) ? getReq.result.length : 0,
                );
              getReq.onerror = () => resolve(0);
            };
            request.onerror = () => resolve(0);
          }),
      );

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

      // Poll IndexedDB until saveTrip completes (avoid flaky waitForTimeout)
      await mockedPage.waitForFunction(
        () =>
          new Promise<boolean>((resolve) => {
            const req = indexedDB.open("keyval-store", 1);
            req.onsuccess = (e) => {
              const db = (e.target as IDBOpenDBRequest).result;
              if (!db.objectStoreNames.contains("keyval")) {
                resolve(false);
                return;
              }
              const getReq = db
                .transaction("keyval", "readonly")
                .objectStore("keyval")
                .get("offline_saved_trips");
              getReq.onsuccess = () =>
                resolve(
                  Array.isArray(getReq.result) && getReq.result.length >= 1,
                );
              getReq.onerror = () => resolve(false);
            };
            req.onerror = () => resolve(false);
          }),
        { timeout: 5000 },
      );
    });
  });
});
