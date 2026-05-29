import { test, expect } from "@playwright/test";
import { mockAllApis } from "../fixtures/api-mocks";

// Each case controls analytics consent explicitly via addInitScript, so start
// from a clean slate — opt out of the global seeded storageState.
test.use({ storageState: { cookies: [], origins: [] } });

const PLAUSIBLE_DOMAIN = "biketripplanner.test";
const PLAUSIBLE_SRC = "https://plausible.test/js/script.js";

/** Injects the Plausible config the way build-time NEXT_PUBLIC_* would. */
async function presetPlausibleEnv(page: Parameters<typeof mockAllApis>[0]) {
  await page.addInitScript(
    ({ domain, src }) => {
      const w = window as Window & {
        __PLAYWRIGHT_PLAUSIBLE_DOMAIN?: string;
        __PLAYWRIGHT_PLAUSIBLE_SRC?: string;
      };
      w.__PLAYWRIGHT_PLAUSIBLE_DOMAIN = domain;
      w.__PLAYWRIGHT_PLAUSIBLE_SRC = src;
    },
    { domain: PLAUSIBLE_DOMAIN, src: PLAUSIBLE_SRC },
  );
}

async function grantAnalyticsConsent(page: Parameters<typeof mockAllApis>[0]) {
  await page.addInitScript(() => {
    localStorage.setItem("cookie-consent", JSON.stringify({ analytics: true }));
  });
}

/** Fails the test if any request hits the Plausible host. */
async function failOnPlausibleRequest(page: Parameters<typeof mockAllApis>[0]) {
  await page.route("https://plausible.test/**", (route) => {
    throw new Error(`Unexpected Plausible request: ${route.request().url()}`);
  });
}

test.describe("Plausible analytics", () => {
  test("does not load without analytics consent", async ({ page }) => {
    await presetPlausibleEnv(page);
    await failOnPlausibleRequest(page);
    await mockAllApis(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("plausible-script")).toHaveCount(0);
  });

  test("does not load when env is configured but consent absent", async ({
    page,
  }) => {
    // Default state: no cookie-consent entry in localStorage.
    await presetPlausibleEnv(page);
    await mockAllApis(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("plausible-script")).toHaveCount(0);
  });

  test("injects the script when consent is granted and env is set", async ({
    page,
  }) => {
    await presetPlausibleEnv(page);
    await grantAnalyticsConsent(page);
    await mockAllApis(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const script = page.locator('script[data-testid="plausible-script"]');
    await expect(script).toHaveAttribute("data-domain", PLAUSIBLE_DOMAIN);
    await expect(script).toHaveAttribute("src", PLAUSIBLE_SRC);
  });

  test("unloads the script when consent is revoked after injection", async ({
    page,
  }) => {
    await presetPlausibleEnv(page);
    await grantAnalyticsConsent(page);
    await mockAllApis(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await expect(
      page.locator('script[data-testid="plausible-script"]'),
    ).toHaveCount(1);

    await page.evaluate(() => {
      localStorage.setItem(
        "cookie-consent",
        JSON.stringify({ analytics: false }),
      );
      window.dispatchEvent(new Event("cookie-consent-change"));
    });

    await expect(
      page.locator('script[data-testid="plausible-script"]'),
    ).toHaveCount(0);
    await expect
      .poll(() => page.evaluate(() => "plausible" in window))
      .toBe(false);
  });

  test("does not inject the script when consent is granted but env is unset", async ({
    page,
  }) => {
    // No presetPlausibleEnv: domain/src unresolved -> no-op even with consent.
    await grantAnalyticsConsent(page);
    await mockAllApis(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await expect(
      page.locator('script[data-testid="plausible-script"]'),
    ).toHaveCount(0);
  });
});
