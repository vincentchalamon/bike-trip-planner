import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

test.describe("Auth flow", () => {
  test("login page shows email form", async ({ page }) => {
    // Mock auth/refresh as 401 so AuthGuard doesn't auto-authenticate
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });

    await page.goto("/login");
    await page.waitForLoadState("networkidle");

    await expect(page.locator('input[type="email"]')).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Recevoir un lien de connexion" }),
    ).toBeVisible();
  });

  test("login page shows confirmation after submit", async ({ page }) => {
    // Mock auth/refresh as 401 (not authenticated)
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });

    // Mock POST /auth/request-link -> 202
    await page.route("**/auth/request-link", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 202, body: "" });
    });

    await page.goto("/login");
    await page.waitForLoadState("networkidle");

    const emailInput = page.locator('input[type="email"]');
    await emailInput.fill("test@example.com");
    await page
      .getByRole("button", { name: "Recevoir un lien de connexion" })
      .click();

    await expect(
      page.getByText(
        "Si votre adresse est enregistrée, vous allez recevoir un email avec un lien de connexion.",
      ),
    ).toBeVisible();
  });

  test("unauthenticated user sees landing page at /", async ({ page }) => {
    // Mock auth/refresh as 401 (no valid session)
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });

    // The home page is now a public landing page — no redirect to /login
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await expect(page).toHaveURL("/");
    await expect(page.getByTestId("landing-page")).toBeVisible();
  });

  test("unauthenticated user is redirected to login when accessing protected route", async ({ page }) => {
    // Mock auth/refresh as 401 (no valid session)
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });

    // Protected routes (e.g. /trips) still redirect to /login
    await page.goto("/trips");
    await page.waitForURL(/\/login/, { timeout: 5000 });

    await expect(page).toHaveURL(/\/login/);
  });

  test("verify page redirects to home on valid token", async ({ page }) => {
    // Mock POST /auth/verify -> 200 with JWT
    await page.route("**/auth/verify", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
      });
    });

    // Mock auth/refresh as 200 so the redirect to / doesn't bounce back to /login
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
      });
    });

    // Mock GET /trips so the home page doesn't fail
    await page.route(
      (url) => url.pathname === "/trips",
      (route, request) => {
        if (request.method() !== "GET") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/ld+json",
          body: JSON.stringify({
            "@context": "/contexts/Trip",
            "@id": "/trips",
            "@type": "hydra:Collection",
            "hydra:totalItems": 0,
            "hydra:member": [],
            member: [],
            totalItems: 0,
          }),
        });
      },
    );

    // Abort Mercure SSE
    await page.route("**/.well-known/mercure*", (route) => route.abort());

    await page.goto("/auth/verify/test-token");
    await page.waitForURL("/", { timeout: 5000 });

    await expect(page).toHaveURL("/");
  });
});
