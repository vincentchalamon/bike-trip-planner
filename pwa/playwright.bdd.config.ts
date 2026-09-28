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
  // Chromium only, CI included: Firefox never ran against these scenarios.
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
