import { test, expect } from "@playwright/test";

/**
 * Public landing header (audit H1) — the landing must expose brand, language,
 * theme and auth entry points at the top of the page, not only in the footer.
 */
test.describe("landing public header", () => {
  test.beforeEach(async ({ page }) => {
    // 401 on refresh → unauthenticated → the landing (with its header) renders.
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });

    await page.goto("/");
    await page.waitForLoadState("networkidle");
  });

  test("renders the public top bar with brand, sign-in and request-access links", async ({
    page,
  }) => {
    const header = page.getByTestId("public-top-bar");
    await expect(header).toBeVisible();

    const brand = page.getByTestId("public-top-bar-brand");
    await expect(brand).toBeVisible();
    await expect(brand).toHaveAttribute("href", "/");

    const login = page.getByTestId("public-top-bar-login");
    await expect(login).toBeVisible();
    await expect(login).toHaveAttribute("href", "/login");

    // Request-access CTA is desktop-only (hidden under the sm breakpoint); at
    // the default 1280px viewport it must be visible.
    const request = page.getByTestId("public-top-bar-request");
    await expect(request).toBeVisible();
    await expect(request).toHaveAttribute("href", "/#early-access");
  });
});
