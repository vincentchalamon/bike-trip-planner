import { test, expect } from "@playwright/test";
import { mockAllApis } from "../fixtures/api-mocks";

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

/** Fails the test if any request hits the Plausible host. */
async function failOnPlausibleRequest(page: Parameters<typeof mockAllApis>[0]) {
  await page.route("https://plausible.test/**", (route) => {
    throw new Error(`Unexpected Plausible request: ${route.request().url()}`);
  });
}

// Plausible is cookieless and stores no personal data, so it loads on env
// configuration alone — no consent gating (see /privacy and ADR-034).
test.describe("Plausible analytics", () => {
  test("injects the script when the env is configured", async ({ page }) => {
    await presetPlausibleEnv(page);
    await mockAllApis(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const script = page.locator('script[data-testid="plausible-script"]');
    await expect(script).toHaveAttribute("data-domain", PLAUSIBLE_DOMAIN);
    await expect(script).toHaveAttribute("src", PLAUSIBLE_SRC);
  });

  test("does not load when the env is unset", async ({ page }) => {
    // No presetPlausibleEnv: domain/src unresolved -> dormant (e.g. beta).
    await failOnPlausibleRequest(page);
    await mockAllApis(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("plausible-script")).toHaveCount(0);
  });

  // Confirms the beta kill-switch (issue #567): an empty env leaves zero
  // analytics footprint — no script, no cookie, and trackEvent is a no-op.
  test("leaves no analytics footprint when the env is unset", async ({
    page,
  }) => {
    await failOnPlausibleRequest(page);
    await mockAllApis(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // No Plausible script injected.
    await expect(page.getByTestId("plausible-script")).toHaveCount(0);

    // window.plausible is never defined, so trackEvent stays a no-op
    // (and does not throw).
    const tracked = await page.evaluate(() => {
      const w = window as Window & { plausible?: unknown };
      return typeof w.plausible;
    });
    expect(tracked).toBe("undefined");

    // No cookie set at all (Plausible is cookieless, and dormant here anyway).
    const cookies = await page.context().cookies();
    expect(cookies).toHaveLength(0);
  });
});
