import { test, expect } from "@playwright/test";
import {
  test as visualTest,
  expect as visualExpect,
  maskRegions,
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
const PUBLIC_PAGES = [
  { name: "landing", path: "/" },
  { name: "login", path: "/login" },
  { name: "faq", path: "/faq" },
  { name: "legal", path: "/legal" },
  { name: "privacy", path: "/privacy" },
];

test.describe("visual baselines (public pages)", () => {
  for (const { name, path } of PUBLIC_PAGES) {
    test(name, async ({ page, baseURL }, testInfo) => {
      const { theme, appLocale } = testInfo.project.metadata as {
        theme: string;
        appLocale: string;
      };
      await page
        .context()
        .addCookies([
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

      await page.goto(path);
      await page.waitForLoadState("networkidle");

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
  visualTest("trip-roadbook", async ({ visualPage, gotoRoadbook }) => {
    await gotoRoadbook();
    await visualExpect(visualPage).toHaveScreenshot("trip-roadbook.png", {
      fullPage: true,
      mask: maskRegions(visualPage),
    });
  });

  // `/trips/new` step 3 — narrative analysis/processing screen.
  visualTest("trip-new-processing", async ({ visualPage, gotoProcessing }) => {
    await gotoProcessing();
    await visualExpect(visualPage).toHaveScreenshot("trip-new-processing.png", {
      fullPage: true,
      mask: maskRegions(visualPage),
    });
  });

  // `/trips/new` step 2 — preview (map + stats + stages + launch CTA). Reached
  // by the same submit flow as the planner preview (`stage-card-N`).
  visualTest("trip-new-preview", async ({ visualPage, gotoPreview }) => {
    await gotoPreview();
    await visualPage.waitForTimeout(500);
    await visualExpect(visualPage).toHaveScreenshot("trip-new-preview.png", {
      fullPage: true,
      mask: maskRegions(visualPage),
    });
  });

  // `/trips` — populated list (override the default empty collection).
  visualTest("trips-populated", async ({ visualPage }) => {
    await visualPage.route(
      (url) => url.pathname === "/trips",
      (route, request) => {
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

  // `/trips` — empty state (the default mock returns an empty collection).
  visualTest("trips-empty", async ({ visualPage }) => {
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
