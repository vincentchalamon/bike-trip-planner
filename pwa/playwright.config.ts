import { defineConfig, devices } from "@playwright/test";

const chromiumExecutable = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;

export default defineConfig({
  testDir: "./tests",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI
    ? [["github"], ["json", { outputFile: "playwright-report.json" }]]
    : "line",
  use: {
    baseURL: "https://localhost",
    ignoreHTTPSErrors: true,
    locale: "fr-FR",
    trace: "on-first-retry",
    screenshot: "only-on-failure",
    // Seed a recorded cookie-consent decision so the bottom consent banner
    // does not overlay the viewport and intercept clicks across the suite.
    // Specs that test the banner itself opt out via `test.use({ storageState: ... })`.
    storageState: "tests/fixtures/consent-storage-state.json",
  },
  projects: process.env.CI
    ? [
        { name: "chromium", use: { ...devices["Desktop Chrome"] } },
        { name: "firefox", use: { ...devices["Desktop Firefox"] } },
        { name: "webkit", use: { ...devices["Desktop Safari"] } },
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
