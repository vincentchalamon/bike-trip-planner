import { test, expect } from "@playwright/test";
import {
  test as visualTest,
  expect as visualExpect,
  maskRegions,
  MAP_SCREEN_SKIP_REASON,
  shouldSkipMapScreen,
} from "./support/visual.fixture";

/**
 * Visual-regression baselines for full pages, across the 6 projects defined in
 * `playwright.visual.config.ts` (device x browser x theme x lang).
 *
 * Run / regenerate with `make visual-test` / `make visual-update` against a
 * running prod stack (`make start`). Baselines are OS/rendering specific and
 * MUST be generated inside the playwright container.
 *
 * Split in two blocks:
 *  - public pages — no auth, theme/locale only;
 *  - authenticated pages — go through the mock chain (`visualPage` fixture):
 *    loaded roadbook, wizard step-3 processing, trips list (populated + empty).
 */
// Each public page declares a stable "ready" testid so we wait for a concrete
// render instead of `networkidle` — which never settles against the prod stack
// (the AI-availability poll #304 + Mercure SSE keep connections open), the root
// cause of the earlier non-deterministic public-page baselines.
const PUBLIC_PAGES = [
  { name: "landing", path: "/", ready: "landing-page" },
  { name: "login", path: "/login", ready: "login-card" },
  { name: "faq", path: "/faq", ready: "faq-back-link" },
  { name: "legal", path: "/legal", ready: "legal-back-link" },
  { name: "privacy", path: "/privacy", ready: "privacy-back-link" },
];

test.describe("visual baselines (public pages)", () => {
  for (const { name, path, ready } of PUBLIC_PAGES) {
    test(name, async ({ page, baseURL }, testInfo) => {
      const { theme, appLocale } = testInfo.project.metadata as {
        theme: string;
        appLocale: string;
      };
      await page.context().addCookies([
        {
          name: "locale",
          value: appLocale,
          url: baseURL ?? "https://localhost",
        },
      ]);
      // next-themes reads the persisted theme before first paint.
      await page.addInitScript((value) => {
        try {
          window.localStorage.setItem("theme", value);
        } catch {
          /* storage unavailable — colorScheme still drives prefers-color-scheme */
        }
      }, theme);
      // Force the anonymous variant deterministically (so `/` renders the
      // landing, not the dashboard) without depending on the live backend.
      await page.route("**/auth/refresh", (route, request) =>
        request.method() === "POST"
          ? route.fulfill({ status: 401, body: "" })
          : route.fallback(),
      );

      await page.goto(path);
      await expect(page.getByTestId(ready)).toBeVisible({ timeout: 15000 });
      // Fonts (Fraunces / Inter Tight) load from the network; wait so glyph
      // metrics are stable before the snapshot.
      await page.evaluate(() => document.fonts.ready);

      await expect(page).toHaveScreenshot(`${name}.png`, {
        fullPage: true,
        // Map tiles load from the network and are non-deterministic.
        mask: [page.locator(".maplibregl-map, [data-testid='map'], canvas")],
      });
    });
  }
});

/**
 * Authenticated pages. The `visualPage` fixture installs `mockAllApis`, applies
 * theme/locale from the project metadata and leaves navigation to each test.
 * Non-deterministic regions (maps, canvases, dates, AI text) are masked.
 */
visualTest.describe("visual baselines (authenticated pages)", () => {
  // `/trips/[id]` roadbook — direct navigation with a stages-bearing detail
  // response (most deterministic path: no SSE timing involved).
  visualTest(
    "trip-roadbook",
    async ({ visualPage, gotoRoadbook }, testInfo) => {
      visualTest.skip(shouldSkipMapScreen(testInfo), MAP_SCREEN_SKIP_REASON);
      await gotoRoadbook();
      await visualExpect(visualPage).toHaveScreenshot("trip-roadbook.png", {
        fullPage: true,
        mask: maskRegions(visualPage),
      });
    },
  );

  // ADR-043: the wizard "Aperçu" (preview) and "Analyse" (processing) screens
  // are gone — the flow collapsed to Saisie → loader → trip view. Their VR
  // baselines (`trip-new-preview`, `trip-new-processing`) were removed.

  // `/trips` — populated list (override the default empty collection).
  visualTest("trips-populated", async ({ visualPage }) => {
    await visualPage.route(
      (url) => url.pathname === "/trips",
      (route, request) => {
        // In the iso-prod build the PWA and API share the `https://localhost`
        // origin, so `/trips` is BOTH the page route and the API collection.
        // Let the top-level document navigation through (it must render the
        // Next.js page); only fulfill the data fetch with the mock collection.
        if (request.resourceType() === "document") return route.continue();
        if (request.method() !== "GET") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/ld+json",
          body: JSON.stringify(tripsCollection(4)),
        });
      },
    );
    await visualPage.goto("/trips");
    await visualExpect(visualPage.getByTestId("trips-grid")).toBeVisible({
      timeout: 10000,
    });
    await visualExpect(visualPage).toHaveScreenshot("trips-populated.png", {
      fullPage: true,
      mask: maskRegions(visualPage),
    });
  });

  // `/trips` — empty state. Re-route `/trips` with the same document guard as
  // `trips-populated` (the shared mock would otherwise fulfill the page
  // navigation itself with JSON on the same-origin iso-prod build).
  visualTest("trips-empty", async ({ visualPage }) => {
    await visualPage.route(
      (url) => url.pathname === "/trips",
      (route, request) => {
        if (request.resourceType() === "document") return route.continue();
        if (request.method() !== "GET") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/ld+json",
          body: JSON.stringify(tripsCollection(0)),
        });
      },
    );
    await visualPage.goto("/trips");
    await visualExpect(
      visualPage.getByTestId("trips-empty-no-trips"),
    ).toBeVisible({ timeout: 10000 });
    await visualExpect(visualPage).toHaveScreenshot("trips-empty.png", {
      fullPage: true,
      mask: maskRegions(visualPage),
    });
  });
});

/** A populated Hydra collection of `count` trips for the `/trips` list. */
function tripsCollection(count: number) {
  const member = Array.from({ length: count }, (_, i) => ({
    "@id": `/trips/trip-${i + 1}`,
    "@type": "Trip",
    id: `trip-${i + 1}`,
    title: `Tour ${i + 1}`,
    startDate: "2026-06-01T00:00:00+00:00",
    endDate: "2026-06-03T00:00:00+00:00",
    totalDistance: 187300,
    stageCount: 3,
    createdAt: "2026-05-01T00:00:00+00:00",
    updatedAt: "2026-05-02T00:00:00+00:00",
    status: i % 2 === 0 ? "analyzed" : "draft",
  }));
  return {
    "@context": "/contexts/Trip",
    "@id": "/trips",
    "@type": "hydra:Collection",
    "hydra:totalItems": count,
    "hydra:member": member,
    member,
    totalItems: count,
  };
}
