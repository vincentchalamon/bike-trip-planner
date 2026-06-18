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

/**
 * Mock GET /users/me/ai-settings (ADR-042). Defaults to unconfigured so the
 * AI section renders its "not configured" baseline; pass `provider` to simulate
 * an account that already has an AI provider set.
 */
async function mockAiSettingsGet(
  page: import("@playwright/test").Page,
  provider: string | null = null,
) {
  await page.route("**/users/me/ai-settings", (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/ld+json",
      body: JSON.stringify(
        provider
          ? { provider, tokenConfigured: true }
          : { tokenConfigured: false },
      ),
    });
  });
}

test.describe("Account settings", () => {
  test("renders all sections for an authenticated user", async ({ page }) => {
    await mockAuthenticated(page);
    await mockAiSettingsGet(page);

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("account-settings-page")).toBeVisible();
    await expect(page.getByTestId("account-section")).toBeVisible();
    await expect(page.getByTestId("preferences-section")).toBeVisible();
    await expect(page.getByTestId("ai-provider-section")).toBeVisible();
    await expect(page.getByTestId("data-section")).toBeVisible();
    await expect(page.getByTestId("danger-zone-section")).toBeVisible();
    await expect(page.getByTestId("logout-section")).toBeVisible();
    // Email from the fake JWT payload (username claim)
    await expect(page.getByTestId("account-email")).toHaveText(
      "test@example.com",
    );
  });

  test("renders the account chrome: top bar, identity rail and footer", async ({
    page,
  }) => {
    await mockAuthenticated(page);

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("top-bar")).toBeVisible();
    await expect(page.getByTestId("account-rail")).toBeVisible();
    await expect(page.getByTestId("account-rail-email")).toHaveText(
      "test@example.com",
    );
    await expect(page.getByTestId("section-footer")).toBeVisible();
    // The help modal is only mounted by the trip planner, so the help button
    // is suppressed here (showHelp={false}).
    await expect(page.getByTestId("help-button")).toHaveCount(0);
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

  test("delete account keeps dialog open and shows error toast on failure", async ({
    page,
  }) => {
    await mockAuthenticated(page);

    let deleteCalled = false;
    await page.route("**/users/me", (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      deleteCalled = true;
      return route.fulfill({ status: 500, body: "" });
    });

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("delete-account-button").click();
    const dialog = page.getByTestId("delete-account-dialog");
    await expect(dialog).toBeVisible();

    await page
      .getByTestId("delete-account-dialog-keyword-input")
      .fill("SUPPRIMER");
    const confirm = page.getByTestId("delete-account-dialog-confirm");
    await expect(confirm).toBeEnabled();
    await confirm.click();

    await expect.poll(() => deleteCalled).toBe(true);
    // The fix: dialog must stay open so the user can retry without retyping.
    await expect(dialog).toBeVisible();
    await expect(page).toHaveURL(/\/account\/settings$/);
  });

  test("export button re-enables after a server error", async ({ page }) => {
    await mockAuthenticated(page);

    let exportCalled = false;
    await page.route("**/users/me/export", (route, request) => {
      if (request.method() !== "GET") return route.fallback();
      exportCalled = true;
      return route.fulfill({ status: 500, body: "" });
    });

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    const button = page.getByTestId("export-data-button");
    await button.click();
    await expect.poll(() => exportCalled).toBe(true);
    // The button must re-enable after the failure so the user can retry.
    await expect(button).toBeEnabled();
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

  test("logout button shows error toast and re-enables on failure", async ({
    page,
  }) => {
    await mockAuthenticated(page);

    await page.route("**/auth/logout", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 500, body: "" });
    });

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    const button = page.getByTestId("logout-button");
    await button.click();

    // Button must re-enable after the failure so the user can retry.
    await expect(button).toBeEnabled();
    // URL must not change — user stays on the settings page.
    await expect(page).toHaveURL(/\/account\/settings$/);
  });
});

test.describe("AI provider settings (ADR-042)", () => {
  test("loads the current provider + token-configured indicator", async ({
    page,
  }) => {
    await mockAuthenticated(page);
    await mockAiSettingsGet(page, "anthropic");

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("ai-provider-select")).toHaveValue(
      "anthropic",
    );
    await expect(page.getByTestId("ai-token-status")).toHaveText(
      "Une clé API est enregistrée.",
    );
    // The token itself is never returned, so the input stays empty.
    await expect(page.getByTestId("ai-token-input")).toHaveValue("");
    // RGPD disclosure is always shown.
    await expect(page.getByTestId("ai-settings-rgpd")).toBeVisible();
  });

  test("saving sends PUT and confirms the configured state", async ({
    page,
  }) => {
    await mockAuthenticated(page);
    await mockAiSettingsGet(page, null);

    let putBody: Record<string, unknown> | null = null;
    await page.route("**/users/me/ai-settings", (route, request) => {
      if (request.method() !== "PUT") return route.fallback();
      putBody = JSON.parse(request.postData() ?? "{}") as Record<
        string,
        unknown
      >;
      return route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify({ provider: "openai", tokenConfigured: true }),
      });
    });

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await page
      .getByTestId("ai-provider-select")
      .selectOption("openai");
    await page.getByTestId("ai-token-input").fill("sk-test-key");
    await page.getByTestId("ai-settings-save").click();

    await expect.poll(() => putBody).toEqual({
      provider: "openai",
      token: "sk-test-key",
    });
    await expect(page.getByTestId("ai-token-status")).toHaveText(
      "Une clé API est enregistrée.",
    );
    // Token is cleared from the input after a successful save.
    await expect(page.getByTestId("ai-token-input")).toHaveValue("");
  });

  test("surfaces a 422 validation error inline", async ({ page }) => {
    await mockAuthenticated(page);
    await mockAiSettingsGet(page, null);

    await page.route("**/users/me/ai-settings", (route, request) => {
      if (request.method() !== "PUT") return route.fallback();
      return route.fulfill({
        status: 422,
        contentType: "application/ld+json",
        body: JSON.stringify({
          violations: [
            { propertyPath: "token", message: "Invalid token format." },
          ],
        }),
      });
    });

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await page
      .getByTestId("ai-provider-select")
      .selectOption("anthropic");
    await page.getByTestId("ai-token-input").fill("bad");
    await page.getByTestId("ai-settings-save").click();

    await expect(page.getByTestId("ai-settings-error")).toHaveText(
      "Invalid token format.",
    );
  });

  test("clearing sends DELETE and resets the form", async ({ page }) => {
    await mockAuthenticated(page);
    await mockAiSettingsGet(page, "gemini");

    let deleteCalled = false;
    await page.route("**/users/me/ai-settings", (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      deleteCalled = true;
      return route.fulfill({ status: 204, body: "" });
    });

    await page.goto("/account/settings");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("ai-provider-select")).toHaveValue("gemini");
    await page.getByTestId("ai-settings-clear").click();

    await expect.poll(() => deleteCalled).toBe(true);
    await expect(page.getByTestId("ai-provider-select")).toHaveValue("");
    await expect(page.getByTestId("ai-token-status")).toHaveText(
      "Aucune clé API enregistrée.",
    );
  });
});
