import { defineConfig, devices } from "@playwright/test";
import { defineBddConfig, cucumberReporter } from "playwright-bdd";

const testDir = defineBddConfig({
  features: "tests/recette/features/**/*.feature",
  steps: [
    "tests/recette/steps/**/*.ts",
    "tests/recette/support/hooks.ts",
    "tests/recette/support/fixtures.ts",
  ],
  outputDir: ".features-gen",
  verbose: false,
});

const chromiumExecutable = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
// Firefox and WebKit run on demand only (`make test-recette-browsers`), never in CI.
const allBrowsers = process.env.PLAYWRIGHT_ALL_BROWSERS === "1";

export default defineConfig({
  testDir,
  // Seeds a real share for the /s/<code_court> steps (no-op without E2E_JWT).
  globalSetup: "./tests/fixtures/shared-trip-seed.ts",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI
    ? [
        ["line"] as [string],
        ["github"] as [string],
        cucumberReporter("html", {
          outputFile: "recette-report/index.html",
        }),
        cucumberReporter("json", {
          outputFile: "recette-report/results.json",
        }),
      ]
    : [
        ["line"] as [string],
        cucumberReporter("html", {
          outputFile: "recette-report/index.html",
        }),
      ],
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
