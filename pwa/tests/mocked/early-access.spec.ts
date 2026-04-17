import { test, expect, type Page } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

/**
 * Mock POST /auth/refresh as 401 to simulate an unauthenticated session.
 */
async function mockUnauthenticated(page: Page) {
  await page.route("**/auth/refresh", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({ status: 401, body: "" });
  });
}

/**
 * Mock POST /auth/refresh as 200 with a fake JWT to simulate an authenticated session.
 */
async function mockAuthenticated(page: Page) {
  await page.route("**/auth/refresh", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
    });
  });

  // Mock GET /trips so the home page loads without error
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
}

test.describe("Early access form", () => {
  test("shows early access form on landing page for unauthenticated user", async ({
    page,
  }) => {
    await mockUnauthenticated(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("early-access-form")).toBeVisible();
    await expect(page.getByTestId("early-access-email-input")).toBeVisible();
    await expect(page.getByTestId("early-access-submit")).toBeVisible();
  });

  test("shows success message after valid email submission", async ({
    page,
  }) => {
    await mockUnauthenticated(page);

    // Mock POST /access-requests -> 202
    await page.route("**/access-requests", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 202,
        contentType: "application/json",
        body: JSON.stringify({ message: "Your request has been received." }),
      });
    });

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("early-access-email-input").fill("test@example.com");
    await page.getByTestId("early-access-submit").click();

    await expect(page.getByTestId("early-access-success")).toBeVisible();
    await expect(page.getByTestId("early-access-form")).not.toBeVisible();
  });

  test("shows throttled message on 429 response", async ({ page }) => {
    await mockUnauthenticated(page);

    // Mock POST /access-requests -> 429
    await page.route("**/access-requests", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 429,
        contentType: "application/json",
        body: JSON.stringify({ message: "Too many requests." }),
      });
    });

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("early-access-email-input").fill("test@example.com");
    await page.getByTestId("early-access-submit").click();

    await expect(page.getByTestId("early-access-throttled")).toBeVisible();
    await expect(page.getByTestId("early-access-form")).not.toBeVisible();
  });

  test("shows validation error for invalid email", async ({ page }) => {
    await mockUnauthenticated(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("early-access-email-input").fill("not-an-email");
    await page.getByTestId("early-access-submit").click();

    await expect(page.getByTestId("early-access-email-error")).toBeVisible();
  });
});

test.describe("CTA navigation", () => {
  test("CTA 'Créer un itinéraire' leads to /login for unauthenticated user", async ({
    page,
  }) => {
    await mockUnauthenticated(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const cta = page.getByTestId("cta-create-trip");
    await expect(cta).toBeVisible();

    // The CTA link should point to /login for unauthenticated users
    await expect(cta).toHaveAttribute("href", "/login");
  });

  test("'Mes voyages' link is visible in trip planner for authenticated user", async ({
    page,
  }) => {
    await mockAuthenticated(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("my-trips-link")).toBeVisible();
    await expect(page.getByTestId("my-trips-link")).toHaveAttribute(
      "href",
      "/trips",
    );
  });
});

test.describe("Login page early access banner", () => {
  test("shows early access banner on login page", async ({ page }) => {
    await mockUnauthenticated(page);
    await page.goto("/login");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("early-access-banner")).toBeVisible();
    await expect(page.getByTestId("early-access-link")).toBeVisible();
    await expect(page.getByTestId("early-access-link")).toHaveAttribute(
      "href",
      "/#early-access",
    );
  });
});

test.describe("Access request verification", () => {
  test("verify page redirects to backend verify endpoint", async ({ page }) => {
    await mockUnauthenticated(page);

    // Intercept any GET to /access-requests/verify?... and redirect back to
    // the landing page with ?access=confirmed. A relative Location avoids
    // dangling absolute URLs that are unreachable in CI.
    let backendVerifyCalled = false;
    await page.route(
      /\/access-requests\/verify\?.*signature=/,
      (route, request) => {
        if (request.method() !== "GET") return route.fallback();
        backendVerifyCalled = true;
        return route.fulfill({
          status: 302,
          headers: { Location: "/?access=confirmed" },
        });
      },
    );

    await page.goto(
      "/access-requests/verify?email=test@example.com&expires=9999999999&signature=abc123",
    );
    await page.waitForLoadState("networkidle");

    expect(backendVerifyCalled).toBe(true);
  });

  test("landing page shows access confirmed message when ?access=confirmed", async ({
    page,
  }) => {
    await mockUnauthenticated(page);
    await page.goto("/?access=confirmed");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("access-confirmed-message")).toBeVisible();
  });
});
