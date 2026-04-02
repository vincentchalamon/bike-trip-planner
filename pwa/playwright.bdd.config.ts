import { defineConfig, devices } from "@playwright/test";
import { defineBddConfig, cucumberReporter } from "playwright-bdd";

const testDir = defineBddConfig({
  features: "tests/recette/features/**/*.feature",
  steps: [
    "tests/recette/steps/**/*.ts",
    "tests/recette/support/hooks.ts",
  ],
  outputDir: ".features-gen",
  verbose: false,
});

const chromiumExecutable = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;

export default defineConfig({
  testDir,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI
    ? [
        "github",
        cucumberReporter("html", {
          outputFile: "recette-report/index.html",
        }),
        cucumberReporter("json", {
          outputFile: "recette-report/results.json",
        }),
      ]
    : [
        "line",
        cucumberReporter("html", {
          outputFile: "recette-report/index.html",
        }),
      ],
  use: {
    baseURL: "https://localhost",
    ignoreHTTPSErrors: true,
    locale: "fr-FR",
    trace: "on-first-retry",
    screenshot: "only-on-failure",
  },
  projects: process.env.CI
    ? [
        { name: "chromium", use: { ...devices["Desktop Chrome"] } },
        { name: "firefox", use: { ...devices["Desktop Firefox"] } },
      ]
    : [
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
