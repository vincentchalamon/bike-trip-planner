import { defineConfig, devices } from "@playwright/test";

const chromiumExecutable = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;

export default defineConfig({
  testDir: "./tests",
  // The documentation screenshot capture (tests/screenshots/) writes image files
  // and is not an assertion suite; it is run on demand via `make screenshots`.
  // The visual-regression suite (tests/visual/) has its own multi-project config
  // and is run on demand via `make visual-test`.
  testIgnore: ["**/screenshots/**", "**/visual/**"],
  // Seeds a real share for the /s/{code} specs (no-op without E2E_JWT).
  globalSetup: "./tests/fixtures/shared-trip-seed.ts",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI
    ? [["line"], ["github"], ["html", { open: "never" }]]
    : "line",
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "https://localhost",
    ignoreHTTPSErrors: true,
    locale: "fr-FR",
    trace: "on-first-retry",
    screenshot: "only-on-failure",
  },
  // Chromium only, CI included: Firefox and WebKit never ran against this suite.
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
