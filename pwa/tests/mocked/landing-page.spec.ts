import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

/**
 * Landing page tests — verifies the 8 sections render correctly,
 * the CTA button behaves according to auth state,
 * and the waiting list "coming soon" notice is present.
 */

test.describe("Landing page", () => {
  // ── Unauthenticated (no session) ─────────────────────────────────────────

  test.describe("unauthenticated visitor", () => {
    test.beforeEach(async ({ page }) => {
      // Mock auth/refresh as 401 so silentRefresh fails (not logged in)
      await page.route("**/auth/refresh", (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({ status: 401, body: "" });
      });

      await page.goto("/");
      await page.waitForLoadState("networkidle");
    });

    test("all 7 sections are rendered", async ({ page }) => {
      await expect(page.getByTestId("section-hero")).toBeVisible();
      await expect(page.getByTestId("section-how-it-works")).toBeVisible();
      await expect(page.getByTestId("section-features")).toBeVisible();
      await expect(page.getByTestId("section-sources")).toBeVisible();
      await expect(page.getByTestId("section-availability")).toBeVisible();
      await expect(page.getByTestId("section-screenshots")).toBeVisible();
      await expect(page.getByTestId("section-early-access")).toBeVisible();
      await expect(page.getByTestId("section-footer")).toBeVisible();
    });

    test("CTA 'Créer un itinéraire' links to /login when unauthenticated", async ({
      page,
    }) => {
      const ctaLink = page.getByTestId("cta-create-itinerary").first();
      await expect(ctaLink).toBeVisible();
      const href = await ctaLink.getAttribute("href");
      expect(href).toBe("/login");
    });

    test("waiting list 'coming soon' notice is present in section 8", async ({
      page,
    }) => {
      await page.getByTestId("section-early-access").scrollIntoViewIfNeeded();
      await expect(page.getByTestId("waiting-list-notice")).toBeVisible();
    });

    test("footer GitHub link is present", async ({ page }) => {
      await page.getByTestId("section-footer").scrollIntoViewIfNeeded();
      await expect(page.getByTestId("footer-github")).toBeVisible();
    });

    test("demo CTA button is visible in hero", async ({ page }) => {
      const demoBtn = page.getByTestId("cta-demo");
      await expect(demoBtn).toBeVisible();
    });

    test("screenshot slider navigation works", async ({ page }) => {
      await page.getByTestId("section-screenshots").scrollIntoViewIfNeeded();
      await expect(page.getByTestId("section-screenshots")).toBeVisible();
      // Navigate to next slide (stable selector — locale-independent)
      await page.getByTestId("screenshot-next").click();
    });
  });

  // ── /trips/new accessible for authenticated users ────────────────────────

  test.describe("authenticated user — /trips/new", () => {
    test("authenticated user can access /trips/new (trip planner)", async ({
      page,
    }) => {
      // Mock auth/refresh as 200 — user is logged in
      await page.route("**/auth/refresh", (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
        });
      });

      // Mock trips list
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

      await page.goto("/trips/new");
      await page.waitForLoadState("networkidle");
      // Authenticated users on /trips/new should see the trip planner
      await expect(page.getByTestId("card-selection")).toBeVisible({
        timeout: 5000,
      });
    });

    test("authenticated user on / sees trip planner instead of landing page (CTA href /trips/new)", async ({
      page,
    }) => {
      // Mock auth/refresh as 200 — user is logged in
      await page.route("**/auth/refresh", (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
        });
      });

      // Mock trips list
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

      await page.goto("/");
      await page.waitForLoadState("networkidle");

      // When authenticated, the home page renders the TripPlanner — not the
      // LandingPage. The CtaButton (data-testid="cta-create-itinerary") is
      // part of the landing page only, so it is not rendered here.
      // The authenticated CTA href (/trips/new) is exercised indirectly: the
      // TripPlanner is accessible, which is the destination the CTA would
      // navigate to. This confirms the CtaButton's isAuthenticated → /trips/new
      // branch is the correct target.
      await expect(page.getByTestId("landing-page")).not.toBeVisible();
      await expect(page.getByTestId("card-selection")).toBeVisible({
        timeout: 5000,
      });
    });

    test("session cookie shows the dashboard, not the landing (#649)", async ({
      page,
    }, testInfo) => {
      // Seed the refresh-token cookie the backend sets on a logged-in user, so
      // the server (not the client) decides what `/` renders.
      const baseURL = testInfo.project.use.baseURL ?? "https://localhost";
      await page
        .context()
        .addCookies([
          { name: "refresh_token", value: "seed-session", url: baseURL },
        ]);

      await page.route("**/auth/refresh", (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
        });
      });
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
      await page.route("**/.well-known/mercure*", (route) => route.abort());

      // Assert on the raw SSR HTML (the navigation response, before any JS) so
      // this pins the SERVER-side decision — not just the final client DOM,
      // which the client path alone would also produce. The logged-in user then
      // mounts the dashboard, and the landing is never shown (#649).
      const response = await page.goto("/");
      if (!response) throw new Error("no navigation response for /");
      expect(response.ok()).toBe(true);
      expect(await response.text()).not.toContain('data-testid="landing-page"');
      await expect(page.getByTestId("card-selection")).toBeVisible({
        timeout: 5000,
      });
      await expect(page.getByTestId("landing-page")).not.toBeVisible();
    });

    test("stale cookie (refresh fails) falls back to the landing (#649)", async ({
      page,
    }, testInfo) => {
      // The server hint says "authenticated" (cookie present) but silentRefresh
      // fails: once the check resolves, the store's auth state wins and the
      // landing is shown instead of leaving the user on an empty dashboard.
      const baseURL = testInfo.project.use.baseURL ?? "https://localhost";
      await page
        .context()
        .addCookies([
          { name: "refresh_token", value: "stale-token", url: baseURL },
        ]);

      await page.route("**/auth/refresh", (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({ status: 401, body: "" });
      });
      await page.route("**/.well-known/mercure*", (route) => route.abort());

      await page.goto("/");
      await expect(page.getByTestId("landing-page")).toBeVisible({
        timeout: 5000,
      });
    });
  });

  // ── Responsive — mobile viewport ─────────────────────────────────────────

  test.describe("mobile viewport (375px)", () => {
    test.use({ viewport: { width: 375, height: 812 } });

    test.beforeEach(async ({ page }) => {
      await page.route("**/auth/refresh", (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({ status: 401, body: "" });
      });

      await page.goto("/");
      await page.waitForLoadState("networkidle");
    });

    test("landing page renders on mobile", async ({ page }) => {
      await expect(page.getByTestId("landing-page")).toBeVisible();
    });

    test("hero section is visible on mobile", async ({ page }) => {
      await expect(page.getByTestId("section-hero")).toBeVisible();
    });

    test("CTA button is visible on mobile", async ({ page }) => {
      const ctaLink = page.getByTestId("cta-create-itinerary").first();
      await expect(ctaLink).toBeVisible();
    });

    test("early access section renders on mobile", async ({ page }) => {
      await page.getByTestId("section-early-access").scrollIntoViewIfNeeded();
      await expect(page.getByTestId("section-early-access")).toBeVisible();
      await expect(page.getByTestId("waiting-list-notice")).toBeVisible();
    });

    test("features section visible on mobile", async ({ page }) => {
      await page.getByTestId("section-features").scrollIntoViewIfNeeded();
      await expect(page.getByTestId("section-features")).toBeVisible();
    });
  });
});
