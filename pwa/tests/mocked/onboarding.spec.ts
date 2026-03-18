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

    // Wait for driver.js animation to finish (400 ms transition) so that
    // __activeElement / __activeStep are set in the rAF callback — destroy()
    // only calls onDestroyed when those values are truthy.
    await page.waitForTimeout(500);

    // Programmatically complete the tour via the test helper exposed by the
    // component. This calls driverObj.destroy() → onDestroyed → markOnboardingDone,
    // exercising the full persistence path without triggering side-effects from
    // clicking step 3 (which would open the config panel and block further clicks).
    await page.evaluate(() => {
      (
        window as Window & { __onboardingDone?: () => void }
      ).__onboardingDone?.();
    });

    // onDestroyed should have fired markOnboardingDone → localStorage
    await page.waitForTimeout(300);
    const flag = await page.evaluate(
      (key) => localStorage.getItem(key),
      ONBOARDING_KEY,
    );
    expect(flag).toBe("true");

    // Reload — tour must not reappear
    await page.reload();
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1500);
    await expect(
      page.locator(".driver-popover.onboarding-popover"),
    ).not.toBeVisible();
  });
});
