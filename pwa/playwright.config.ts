import { defineConfig, devices } from "@playwright/test";

const chromiumExecutable = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
// Firefox and WebKit run on demand only (`make test-e2e-browsers`), never in CI:
// tripling the suite there is not worth it for engines checked before a release.
const allBrowsers = process.env.PLAYWRIGHT_ALL_BROWSERS === "1";

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
    ...(allBrowsers
      ? [
          {
            name: "firefox",
            // Headless Firefox exposes no WebGL2, whatever its prefs, so the map would never
            // render: it runs headed, under xvfb in the Make target.
            use: { ...devices["Desktop Firefox"], headless: false },
          },
          { name: "webkit", use: { ...devices["Desktop Safari"] } },
        ]
      : []),
  ],
});
