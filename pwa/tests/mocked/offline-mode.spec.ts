import { test, expect } from "../fixtures/base.fixture";
import type { Page } from "@playwright/test";
import { mockAllApis } from "../fixtures/api-mocks";

// Flip `navigator.onLine` along with the event: `useOnlineStatus` reads the
// property, as a real connectivity change would update it.
async function setConnectivity(page: Page, online: boolean): Promise<void> {
  await page.evaluate((value) => {
    Object.defineProperty(navigator, "onLine", {
      configurable: true,
      get: () => value,
    });
    window.dispatchEvent(new Event(value ? "online" : "offline"));
  }, online);
}

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
      await setConnectivity(mockedPage, false);

      const banner = mockedPage.getByTestId("offline-banner");
      await expect(banner).toBeVisible({ timeout: 3000 });
      // locale-agnostic: matches either French or English copy
      await expect(banner).toContainText(/Hors ligne|Offline/);
    });

    test("offline banner has role=status and aria-live=polite", async ({
      mockedPage,
    }) => {
      await setConnectivity(mockedPage, false);

      const banner = mockedPage.getByTestId("offline-banner");
      await expect(banner).toBeVisible({ timeout: 3000 });
      await expect(banner).toHaveAttribute("role", "status");
      await expect(banner).toHaveAttribute("aria-live", "polite");
    });

    test("shows reconnection banner when back online after being offline", async ({
      mockedPage,
    }) => {
      await setConnectivity(mockedPage, false);
      await expect(mockedPage.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });

      await setConnectivity(mockedPage, true);

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

      await setConnectivity(page, false);
      await expect(page.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });

      await setConnectivity(page, true);
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
      await setConnectivity(mockedPage, false);

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

      await setConnectivity(page, false);

      await expect(page.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });

      const gpxCard = page.getByTestId("card-gpx");
      await expect(gpxCard).toHaveAttribute("data-disabled", "true");
    });

    test("magic-link input re-enabled when back online", async ({
      mockedPage,
    }) => {
      await setConnectivity(mockedPage, false);
      await expect(mockedPage.getByTestId("offline-banner")).toBeVisible({
        timeout: 3000,
      });
      const input = mockedPage.getByTestId("magic-link-input");
      await expect(input).toBeDisabled();

      await setConnectivity(mockedPage, true);
      await expect(input).toBeEnabled({ timeout: 3000 });
    });
  });

  // NOTE: the "IndexedDB persistence" describe block was removed in #649 —
  // front-side trip snapshots ("Mes voyages sauvegardés") are no longer
  // persisted, so there is nothing to assert in IndexedDB here.
});
