import { test, expect } from "@playwright/test";
import { mockAllApis } from "../fixtures/api-mocks";

const ONBOARDING_KEY = "bike-trip-planner:onboarding-done";

/** Enables the onboarding tour despite being in a WebDriver session. */
async function enableOnboardingForTest(
  page: Parameters<typeof mockAllApis>[0],
) {
  await page.addInitScript(() => {
    (
      window as Window & { __PLAYWRIGHT_SHOW_ONBOARDING?: boolean }
    ).__PLAYWRIGHT_SHOW_ONBOARDING = true;
  });
}

test.describe("Onboarding tour", () => {
  test("shows on first visit", async ({ page }) => {
    await enableOnboardingForTest(page);
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // Tour popover should appear after the 800 ms startup delay
    await expect(
      page.locator(".driver-popover.onboarding-popover"),
    ).toBeVisible({ timeout: 3000 });
  });

  test("does not reappear after localStorage flag is set", async ({ page }) => {
    await enableOnboardingForTest(page);
    // Simulate having completed the tour by setting the localStorage flag
    await page.addInitScript(() => {
      localStorage.setItem("bike-trip-planner:onboarding-done", "true");
    });

    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // Give the tour 1.5 s to potentially appear — it must not
    await page.waitForTimeout(1500);
    await expect(
      page.locator(".driver-popover.onboarding-popover"),
    ).not.toBeVisible();
  });

  test("does not show when already seen (navigator.webdriver guard)", async ({
    page,
  }) => {
    // Default Playwright context: navigator.webdriver = true, no flag set
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.waitForTimeout(1500);
    await expect(
      page.locator(".driver-popover.onboarding-popover"),
    ).not.toBeVisible();
  });

  test("completes tour and persists done flag to localStorage", async ({
    page,
  }) => {
    await enableOnboardingForTest(page);
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // Wait for the tour to appear
    await expect(
      page.locator(".driver-popover.onboarding-popover"),
    ).toBeVisible({ timeout: 3000 });

    // The component exposes its test helper once the first step is highlighted,
    // i.e. once destroy() will run onDestroyed; a fixed wait for the animation was
    // too short on WebKit.
    await page.waitForFunction(
      () =>
        typeof (window as Window & { __onboardingDone?: () => void })
          .__onboardingDone === "function",
    );

    // Programmatically complete the tour: driverObj.destroy() → onDestroyed →
    // markOnboardingDone, the full persistence path without clicking through.
    await page.evaluate(() => {
      (
        window as Window & { __onboardingDone?: () => void }
      ).__onboardingDone?.();
    });

    await expect
      .poll(() =>
        page.evaluate((key) => localStorage.getItem(key), ONBOARDING_KEY),
      )
      .toBe("true");

    // Reload — tour must not reappear
    await page.reload();
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1500);
    await expect(
      page.locator(".driver-popover.onboarding-popover"),
    ).not.toBeVisible();
  });
});
