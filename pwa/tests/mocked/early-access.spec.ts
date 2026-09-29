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

  test("early-access block has no 'create itinerary' CTA and a brand submit (#649)", async ({
    page,
  }) => {
    await mockUnauthenticated(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const section = page.getByTestId("section-early-access");
    await section.scrollIntoViewIfNeeded();

    // The "Créer un itinéraire" CTA was removed from this block (it stays in
    // the hero only).
    await expect(section.getByTestId("cta-create-itinerary")).toHaveCount(0);

    // The submit button uses the amber brand fill (same as the hero CTA).
    const submit = page.getByTestId("early-access-submit");
    await expect(submit).toBeVisible();
    await expect(submit).toHaveClass(/bg-brand-fill/);

    // The email input is rendered on a white background.
    const input = page.getByTestId("early-access-email-input");
    await expect(input).toHaveClass(/bg-white/);
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

  test("shows error message on server failure (not a false 'thank you')", async ({
    page,
  }) => {
    await mockUnauthenticated(page);

    // Mock POST /access-requests -> 500 (e.g. mailer down). The bug was that any
    // non-429 status was treated as success; a 5xx must now surface as an error.
    await page.route("**/access-requests", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 500,
        contentType: "application/json",
        body: JSON.stringify({ message: "Internal error." }),
      });
    });

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("early-access-email-input").fill("test@example.com");
    await page.getByTestId("early-access-submit").click();

    await expect(page.getByTestId("early-access-error")).toBeVisible();
    await expect(page.getByTestId("early-access-success")).toHaveCount(0);
  });

  test("shows validation error for invalid email", async ({ page }) => {
    await mockUnauthenticated(page);

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    await page.getByTestId("early-access-email-input").fill("not-an-email");
    await page.getByTestId("early-access-submit").click();

    await expect(page.getByTestId("early-access-email-error")).toBeVisible();
  });

  test("shows validation error when submitting empty email", async ({
    page,
  }) => {
    await mockUnauthenticated(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

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

    const cta = page.getByTestId("cta-create-itinerary").first();
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

    await expect(page.getByTestId("nav-my-trips")).toBeVisible();
    await expect(page.getByTestId("nav-my-trips")).toHaveAttribute(
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
  test("verify page posts the fragment payload to the backend", async ({
    page,
  }) => {
    await mockUnauthenticated(page);

    // The signed payload rides in the fragment, which the browser never sends:
    // the page POSTs it, then lands on the confirmation.
    let posted: unknown;
    await page.route("**/access-requests/verify", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      posted = request.postDataJSON();
      return route.fulfill({ status: 204 });
    });

    await page.goto(
      "/access-requests/verify#id=0199a1b2-0000-7000-8000-000000000001&expires=9999999999&signature=abc123",
    );
    await page.waitForURL("/?access=confirmed", { timeout: 5000 });

    expect(posted).toEqual({
      id: "0199a1b2-0000-7000-8000-000000000001",
      expires: "9999999999",
      signature: "abc123",
    });
  });

  test("landing page shows access confirmed message when ?access=confirmed", async ({
    page,
  }) => {
    await mockUnauthenticated(page);
    await page.goto("/?access=confirmed");
    await page.waitForLoadState("networkidle");

    await expect(page.getByTestId("access-confirmed-message")).toBeVisible();
  });

  test("verify page with missing params redirects to home", async ({
    page,
  }) => {
    await mockUnauthenticated(page);
    await page.goto("/access-requests/verify");
    await page.waitForURL("/", { timeout: 5000 });

    await expect(page).toHaveURL("/");
  });
});
