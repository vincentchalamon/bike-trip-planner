import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

const PAGES = [
  { path: "/legal", testid: "legal-back-link", title: "Mentions légales" },
  {
    path: "/privacy",
    testid: "privacy-back-link",
    title: "Politique de confidentialité",
  },
] as const;

for (const { path, testid, title } of PAGES) {
  test.describe(`${path} page`, () => {
    test("is publicly accessible without authentication", async ({ page }) => {
      await page.route("**/auth/refresh", (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({ status: 401, body: "" });
      });

      await page.goto(path);
      await page.waitForLoadState("networkidle");

      // Should NOT redirect to /login
      await expect(page).toHaveURL(path);
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    });

    test("renders the localized title in French by default", async ({
      page,
    }) => {
      await page.goto(path);
      await page.waitForLoadState("networkidle");

      await expect(page.getByRole("heading", { name: title })).toBeVisible({
        timeout: 5000,
      });
    });

    test("back-to-home link navigates to /", async ({ page }) => {
      await page.route("**/auth/refresh", (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
        });
      });

      await page.goto(path);
      await page.waitForLoadState("networkidle");

      const backLink = page.getByTestId(testid);
      await expect(backLink).toBeVisible();
      await backLink.click();
      await expect(page).toHaveURL("/");
    });
  });
}
