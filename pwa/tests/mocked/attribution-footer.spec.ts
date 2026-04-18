import { test, expect } from "@playwright/test";

test.describe("attribution footer", () => {
  test.beforeEach(async ({ page }) => {
    // Render unauthenticated so the landing page is shown
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });
  });

  test('shows "À propos des données" link on the landing page', async ({
    page,
  }) => {
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const link = page.getByTestId("attribution-footer-link");
    await expect(link).toBeVisible();
  });

  test('shows "À propos des données" link on the login page', async ({
    page,
  }) => {
    await page.goto("/login");
    await page.waitForLoadState("networkidle");

    const link = page.getByTestId("attribution-footer-link");
    await expect(link).toBeVisible();
  });

  test("clicking the link opens the attribution modal", async ({ page }) => {
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const link = page.getByTestId("attribution-footer-link");
    await link.click();

    const modal = page.getByTestId("attribution-modal");
    await expect(modal).toBeVisible();
  });

  test("modal contains all four data sources", async ({ page }) => {
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("attribution-footer-link").click();

    const list = page.getByTestId("attribution-list");
    await expect(list).toBeVisible();

    await expect(page.getByTestId("attribution-osm-link")).toBeVisible();
    await expect(
      page.getByTestId("attribution-datatourisme-link"),
    ).toBeVisible();
    await expect(page.getByTestId("attribution-wikidata-link")).toBeVisible();
    await expect(page.getByTestId("attribution-datagouv-link")).toBeVisible();
  });

  test("ODbL link points to the correct URL", async ({ page }) => {
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("attribution-footer-link").click();

    const osmLink = page.getByTestId("attribution-osm-link");
    await expect(osmLink).toHaveAttribute(
      "href",
      "https://opendatacommons.org/licenses/odbl/",
    );
  });

  test("Wikidata CC0 link points to the correct URL", async ({ page }) => {
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("attribution-footer-link").click();

    const wikidataLink = page.getByTestId("attribution-wikidata-link");
    await expect(wikidataLink).toHaveAttribute(
      "href",
      "https://creativecommons.org/publicdomain/zero/1.0/",
    );
  });

  test("modal can be closed", async ({ page }) => {
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("attribution-footer-link").click();

    const modal = page.getByTestId("attribution-modal");
    await expect(modal).toBeVisible();

    // Close via the X button (DialogClose)
    await page.keyboard.press("Escape");
    await expect(modal).not.toBeVisible();
  });
});
