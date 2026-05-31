import { defineConfig, devices } from "@playwright/test";

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
export default defineConfig({
  testDir: "./tests/screenshots",
  reporter: "line",
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "https://localhost",
    ignoreHTTPSErrors: true,
    locale: "fr-FR",
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
