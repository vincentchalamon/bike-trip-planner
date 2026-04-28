import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

/**
 * Error pages restyle (issue #389) — verify the 404 page renders with the new
 * design system tokens, French copy, illustration and CTA.
 *
 * Triggering error.tsx (route boundary) or global-error.tsx (root layout
 * boundary) in a real browser navigation requires a dedicated test-only route
 * to be shipped to the app bundle, which is intentionally avoided here. Their
 * restyle is otherwise straightforward and visually validated.
 */
test.describe("404 / not-found page", () => {
  test("renders the redesigned 'Hors-piste' page on unknown URL", async ({
    page,
  }) => {
    // AuthGuard redirects non-public paths to /login when unauthenticated, so
    // we must mock auth as authenticated to let the 404 page render.
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
      });
    });

    const response = await page.goto("/this-route-does-not-exist-389");
    // Next.js returns a 404 status for the not-found page.
    expect(response?.status()).toBe(404);
    await page.waitForLoadState("networkidle");

    // Page wrapper renders.
    await expect(page.getByTestId("not-found-page")).toBeVisible();

    // Title in French (Fraunces / font-serif).
    const title = page.getByTestId("not-found-title");
    await expect(title).toHaveText("Hors-piste");

    // Subtitle in French.
    await expect(page.getByTestId("not-found-subtitle")).toHaveText(
      "Cette page n'existe pas ou a été déplacée.",
    );

    // SVG illustration is present and has an accessible label.
    const illustration = page.getByTestId("not-found-illustration");
    await expect(illustration).toBeVisible();
    await expect(illustration).toHaveAttribute(
      "aria-label",
      "Cycliste perdu en montagne",
    );

    // CTA button links back to the home page.
    const homeLink = page.getByTestId("not-found-home-link");
    await expect(homeLink).toBeVisible();
    await expect(homeLink).toHaveText("Retour à l'accueil");
    await expect(homeLink).toHaveAttribute("href", "/");
  });

  test("uses the warm paper surface token as background", async ({ page }) => {
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
      });
    });

    await page.goto("/another-missing-route-389");
    await page.waitForLoadState("networkidle");

    const wrapper = page.getByTestId("not-found-page");
    // The warm paper token resolves to rgb(250, 247, 240) in light mode.
    await expect(wrapper).toHaveCSS("background-color", "rgb(250, 247, 240)");
  });
});
