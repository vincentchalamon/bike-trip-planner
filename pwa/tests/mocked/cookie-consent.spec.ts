import { test, expect, type Page } from "@playwright/test";
import { mockAllApis } from "../fixtures/api-mocks";

// This suite asserts the banner's own behaviour, so it must start with no
// recorded consent — opt out of the global seeded storageState.
test.use({ storageState: { cookies: [], origins: [] } });

const PLAUSIBLE_DOMAIN = "biketripplanner.test";
const PLAUSIBLE_SRC = "https://plausible.test/js/script.js";

/** Injects the Plausible config the way build-time NEXT_PUBLIC_* would. */
async function presetPlausibleEnv(page: Page) {
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
async function failOnPlausibleRequest(page: Page) {
  await page.route("https://plausible.test/**", (route) => {
    throw new Error(`Unexpected Plausible request: ${route.request().url()}`);
  });
}

async function readStoredConsent(page: Page) {
  return page.evaluate(() => {
    const raw = localStorage.getItem("cookie-consent");
    return raw ? (JSON.parse(raw) as { analytics?: boolean }) : null;
  });
}

test.describe("Cookie consent banner", () => {
  test("is visible on first visit", async ({ page }) => {
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("cookie-banner")).toBeVisible();
    await expect(page.getByTestId("cookie-accept-all")).toBeVisible();
    await expect(page.getByTestId("cookie-reject-all")).toBeVisible();
    await expect(page.getByTestId("cookie-customize")).toBeVisible();
  });

  test("is not shown again after a decision was recorded", async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem(
        "cookie-consent",
        JSON.stringify({ analytics: false }),
      );
    });
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("cookie-banner")).toHaveCount(0);
  });

  test("'Reject all' records analytics:false, no Plausible request, banner dismissed persistently", async ({
    page,
  }) => {
    await presetPlausibleEnv(page);
    await failOnPlausibleRequest(page);
    await mockAllApis(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("cookie-reject-all").click();

    await expect(page.getByTestId("cookie-banner")).toHaveCount(0);
    expect(await readStoredConsent(page)).toEqual({ analytics: false });
    await expect(page.getByTestId("plausible-script")).toHaveCount(0);

    // Persistent: reload keeps the banner dismissed.
    await page.reload();
    await page.waitForLoadState("networkidle");
    await expect(page.getByTestId("cookie-banner")).toHaveCount(0);
  });

  test("'Accept all' records analytics:true and loads Plausible without reload", async ({
    page,
  }) => {
    await presetPlausibleEnv(page);
    await mockAllApis(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("cookie-accept-all").click();

    await expect(page.getByTestId("cookie-banner")).toHaveCount(0);
    expect(await readStoredConsent(page)).toEqual({ analytics: true });

    const script = page.locator('script[data-testid="plausible-script"]');
    await expect(script).toHaveAttribute("data-domain", PLAUSIBLE_DOMAIN);
    await expect(script).toHaveAttribute("src", PLAUSIBLE_SRC);
  });
});

test.describe("Cookie granularity modal", () => {
  test("'Customize' opens the modal with both categories and a privacy link", async ({
    page,
  }) => {
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("cookie-customize").click();

    await expect(page.getByTestId("cookie-modal")).toBeVisible();
    await expect(page.getByTestId("cookie-category-technical")).toBeVisible();
    await expect(page.getByTestId("cookie-category-analytics")).toBeVisible();
    // Technical toggle is on and not changeable.
    await expect(page.getByTestId("cookie-toggle-technical")).toBeDisabled();
    await expect(page.getByTestId("cookie-modal-privacy-link")).toHaveAttribute(
      "href",
      "/privacy",
    );
  });

  test("saving with analytics toggled on records analytics:true", async ({
    page,
  }) => {
    await presetPlausibleEnv(page);
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("cookie-customize").click();
    await page.getByTestId("cookie-toggle-analytics").click();
    await page.getByTestId("cookie-modal-save").click();

    await expect(page.getByTestId("cookie-modal")).toHaveCount(0);
    await expect(page.getByTestId("cookie-banner")).toHaveCount(0);
    expect(await readStoredConsent(page)).toEqual({ analytics: true });

    const script = page.locator('script[data-testid="plausible-script"]');
    await expect(script).toHaveAttribute("data-domain", PLAUSIBLE_DOMAIN);
  });

  test("saving with analytics left off records analytics:false and no Plausible", async ({
    page,
  }) => {
    await presetPlausibleEnv(page);
    await failOnPlausibleRequest(page);
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("cookie-customize").click();
    await page.getByTestId("cookie-modal-save").click();

    await expect(page.getByTestId("cookie-modal")).toHaveCount(0);
    expect(await readStoredConsent(page)).toEqual({ analytics: false });
    await expect(page.getByTestId("plausible-script")).toHaveCount(0);
  });

  test("privacy link navigates to /privacy", async ({ page }) => {
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("cookie-customize").click();
    await page.getByTestId("cookie-modal-privacy-link").click();
    await expect(page).toHaveURL("/privacy");
  });

  test("unsaved toggle is discarded when modal is dismissed and reopened", async ({
    page,
  }) => {
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // Open modal and toggle analytics ON without saving.
    await page.getByTestId("cookie-customize").click();
    await expect(page.getByTestId("cookie-toggle-analytics")).not.toBeChecked();
    await page.getByTestId("cookie-toggle-analytics").click();
    await expect(page.getByTestId("cookie-toggle-analytics")).toBeChecked();

    // Dismiss without recording any consent.
    await page.keyboard.press("Escape");
    await expect(page.getByTestId("cookie-modal")).toHaveCount(0);
    expect(await readStoredConsent(page)).toBeNull();

    // Reopen — switch must be back to its initial (false) state.
    await page.getByTestId("cookie-customize").click();
    await expect(page.getByTestId("cookie-toggle-analytics")).not.toBeChecked();
  });
});
