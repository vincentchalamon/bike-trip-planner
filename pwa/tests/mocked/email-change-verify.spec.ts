import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

/**
 * E2E coverage for the email-change verification page (#777).
 *
 * The page lives under the auth-protected (app) route group, so AuthGuard runs
 * a silent refresh on mount: we mock POST /auth/refresh with a fake JWT to
 * establish a session before navigating to the verify URL. The page then POSTs
 * the token to /users/me/email-change/verify and renders one of three branches
 * (verifying spinner / success card / error card).
 */
async function mockAuthenticated(page: import("@playwright/test").Page) {
  await page.route("**/auth/refresh", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
    });
  });
  await page.route("**/.well-known/mercure*", (route) => route.abort());
}

const VERIFY_URL = "/account/email-change/verify/test-token";

test.describe("Email change verification (#777)", () => {
  test("valid token shows the success card and updates the session", async ({
    page,
  }) => {
    await mockAuthenticated(page);

    let verifyCalled = false;
    await page.route("**/users/me/email-change/verify", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      verifyCalled = true;
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ email: "new@example.com" }),
      });
    });

    await page.goto(VERIFY_URL);
    await page.waitForLoadState("networkidle");

    await expect.poll(() => verifyCalled).toBe(true);
    await expect(page.getByTestId("email-change-success")).toBeVisible();
    await expect(
      page
        .getByTestId("email-change-success")
        .getByText("Ton adresse e-mail a été mise à jour."),
    ).toBeVisible();

    // The back button returns to the account settings page.
    await page.getByTestId("email-change-back-button").click();
    await expect(page).toHaveURL(/\/account\/settings$/);
  });

  test("invalid or expired token shows the error card", async ({ page }) => {
    await mockAuthenticated(page);

    await page.route("**/users/me/email-change/verify", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 422,
        contentType: "application/ld+json",
        body: JSON.stringify({ detail: "Lien invalide ou expiré." }),
      });
    });

    await page.goto(VERIFY_URL);
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("email-change-failed")).toBeVisible();
    await expect(
      page.getByText("Lien invalide ou expiré. Relance une demande"),
    ).toBeVisible();
    // The success card must never appear on failure.
    await expect(page.getByTestId("email-change-success")).toHaveCount(0);
  });
});
