import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

/**
 * E2E coverage for the account settings page (#383).
 *
 * The page is auth-protected: AuthGuard runs a silent refresh on mount, so we
 * mock POST /auth/refresh with a fake JWT to establish an authenticated
 * session before navigating to /account/settings.
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

test.describe("Account settings", () => {
  test("renders all sections for an authenticated user", async ({ page }) => {
    await mockAuthenticated(page);

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("account-settings-page")).toBeVisible();
    await expect(page.getByTestId("account-section")).toBeVisible();
    await expect(page.getByTestId("preferences-section")).toBeVisible();
    await expect(page.getByTestId("data-section")).toBeVisible();
    await expect(page.getByTestId("danger-zone-section")).toBeVisible();
    await expect(page.getByTestId("logout-section")).toBeVisible();
    // Email from the fake JWT payload (username claim)
    await expect(page.getByTestId("account-email")).toHaveText(
      "test@example.com",
    );
  });

  test("unauthenticated user is redirected to login", async ({ page }) => {
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });

    await page.goto("/account/settings");
    await page.waitForURL(/\/login/, { timeout: 5000 });
    await expect(page).toHaveURL(/\/login/);
  });

  test("export button calls GET /users/me/export", async ({ page }) => {
    await mockAuthenticated(page);

    let exportCalled = false;
    await page.route("**/users/me/export", (route, request) => {
      if (request.method() !== "GET") return route.fallback();
      exportCalled = true;
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        headers: {
          "Content-Disposition":
            'attachment; filename="bike-trip-planner-export.json"',
        },
        body: JSON.stringify({ profile: {}, trips: [] }),
      });
    });

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("export-data-button").click();
    await expect.poll(() => exportCalled).toBe(true);
  });

  test("change email button triggers a magic link request", async ({
    page,
  }) => {
    await mockAuthenticated(page);

    let linkRequested = false;
    await page.route("**/auth/request-link", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      linkRequested = true;
      return route.fulfill({ status: 202, body: "" });
    });

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("change-email-button").click();
    await expect.poll(() => linkRequested).toBe(true);
  });

  test("delete account requires typing SUPPRIMER then logs out", async ({
    page,
  }) => {
    await mockAuthenticated(page);

    let deleteCalled = false;
    await page.route("**/users/me", (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      deleteCalled = true;
      return route.fulfill({ status: 204, body: "" });
    });
    await page.route("**/auth/logout", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 204, body: "" });
    });

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("delete-account-button").click();
    const dialog = page.getByTestId("delete-account-dialog");
    await expect(dialog).toBeVisible();

    const confirm = page.getByTestId("delete-account-dialog-confirm");
    // Disabled until the keyword is typed exactly
    await expect(confirm).toBeDisabled();

    await page
      .getByTestId("delete-account-dialog-keyword-input")
      .fill("SUPPRIMER");
    await expect(confirm).toBeEnabled();

    await confirm.click();
    await expect.poll(() => deleteCalled).toBe(true);
    // After deletion the user is logged out and redirected home
    await page.waitForURL("/", { timeout: 5000 });
  });

  test("logout button logs out and redirects home", async ({ page }) => {
    await mockAuthenticated(page);

    let logoutCalled = false;
    await page.route("**/auth/logout", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      logoutCalled = true;
      return route.fulfill({ status: 204, body: "" });
    });

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("logout-button").click();
    await expect.poll(() => logoutCalled).toBe(true);
    await page.waitForURL("/", { timeout: 5000 });
  });
});
