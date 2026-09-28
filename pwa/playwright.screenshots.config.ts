import { defineConfig, devices } from "@playwright/test";

interface ScreenshotOptions {
  tripNavigationTimeout: number;
}

const chromiumExecutable = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;

/**
 * Dedicated config for the documentation screenshot capture
 * (`tests/screenshots/capture.spec.ts`), run via `make screenshots`.
 *
 * The main `playwright.config.ts` ignores `**\/screenshots\/**` so the capture
 * never runs during `make test-e2e`; that same ignore would also skip the file
 * when targeted explicitly, hence this separate config scoped to the screenshots
 * directory with no ignore.
 */
export default defineConfig<ScreenshotOptions>({
  testDir: "./tests/screenshots",
  globalSetup: "./tests/screenshots/global-setup.ts",
  reporter: "line",
  // Room for the raised navigation budget below.
  timeout: 90_000,
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "https://localhost",
    ignoreHTTPSErrors: true,
    locale: "fr-FR",
    // The capture runs against the dev server, slower than the E2E build: the
    // E2E default (5s) is too short there even with the routes pre-compiled.
    tripNavigationTimeout: 30_000,
  },
  projects: [
    {
      name: "chromium",
      use: {
        ...devices["Desktop Chrome"],
        ...(chromiumExecutable && {
          launchOptions: { executablePath: chromiumExecutable },
        }),
      },
    },
  ],
});
