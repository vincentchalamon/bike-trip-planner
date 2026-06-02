import { test, expect } from "@playwright/test";

/**
 * Visual-regression baselines for the public pages, across the 6 projects
 * defined in `playwright.visual.config.ts` (device x browser x theme x lang).
 *
 * Run / regenerate with `make visual-test` / `make visual-update` against a
 * running prod stack (`make start`). The authenticated "loaded trip" baseline
 * needs the mocked fixture chain (createFullTrip) and is added when the
 * baselines are first generated in Sprint 35.3.
 */
const PAGES = [
  { name: "landing", path: "/" },
  { name: "login", path: "/login" },
  { name: "faq", path: "/faq" },
  { name: "legal", path: "/legal" },
  { name: "privacy", path: "/privacy" },
];

test.describe("visual baselines (public pages)", () => {
  for (const { name, path } of PAGES) {
    test(name, async ({ page, baseURL }, testInfo) => {
      const { theme, appLocale } = testInfo.project.metadata as {
        theme: string;
        appLocale: string;
      };
      await page.context().addCookies([
        { name: "locale", value: appLocale, url: baseURL ?? "https://localhost" },
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
