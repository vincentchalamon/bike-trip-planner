import { defineConfig, devices } from "@playwright/test";

/**
 * Visual-regression suite (`tests/visual/`), run via `make visual-test` and
 * regenerated via `make visual-update`. Kept in a dedicated config (and ignored
 * by the main `playwright.config.ts`) so it never runs during `make test-e2e`.
 *
 * The 6 projects mirror the Sprint 35 audit-visuel matrix
 * (device x browser engine x theme x language). Baselines are OS/rendering
 * specific and MUST be generated inside the `mcr.microsoft.com/playwright`
 * container (`make visual-update`), never on a host, or CI will diff against
 * different font/AA rendering.
 */
const COMBOS = [
  {
    name: "desktop-1920-chromium-light-fr",
    device: "Desktop Chrome",
    viewport: { width: 1920, height: 1080 },
    colorScheme: "light" as const,
    theme: "light",
    locale: "fr",
  },
  {
    name: "desktop-1920-firefox-dark-en",
    device: "Desktop Firefox",
    viewport: { width: 1920, height: 1080 },
    colorScheme: "dark" as const,
    theme: "dark",
    locale: "en",
  },
  {
    name: "desktop-1440-chromium-dark-fr",
    device: "Desktop Chrome",
    viewport: { width: 1440, height: 900 },
    colorScheme: "dark" as const,
    theme: "dark",
    locale: "fr",
  },
  {
    name: "tablet-768-chromium-light-en",
    device: "Desktop Chrome",
    viewport: { width: 768, height: 1024 },
    colorScheme: "light" as const,
    theme: "light",
    locale: "en",
  },
  {
    name: "mobile-375-chromium-light-fr",
    device: "Desktop Chrome",
    viewport: { width: 375, height: 812 },
    colorScheme: "light" as const,
    theme: "light",
    locale: "fr",
  },
  {
    name: "mobile-375-webkit-dark-en",
    device: "Desktop Safari",
    viewport: { width: 375, height: 812 },
    colorScheme: "dark" as const,
    theme: "dark",
    locale: "en",
  },
];

export default defineConfig({
  testDir: "./tests/visual",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  reporter: "line",
  snapshotPathTemplate: "{testDir}/__screenshots__/{projectName}/{arg}{ext}",
  expect: {
    toHaveScreenshot: {
      maxDiffPixelRatio: 0.02,
      animations: "disabled",
      caret: "hide",
    },
  },
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "https://localhost",
    ignoreHTTPSErrors: true,
  },
  projects: COMBOS.map((c) => ({
    name: c.name,
    use: {
      ...devices[c.device],
      viewport: c.viewport,
      colorScheme: c.colorScheme,
      locale: c.locale === "fr" ? "fr-FR" : "en-US",
    },
    metadata: { theme: c.theme, appLocale: c.locale },
  })),
});
