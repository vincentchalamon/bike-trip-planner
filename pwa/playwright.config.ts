import { defineConfig, devices } from "@playwright/test";

const chromiumExecutable = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;

export default defineConfig({
  testDir: "./tests",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: "html",
  use: {
    baseURL: "http://localhost:3000",
    ignoreHTTPSErrors: true,
    locale: "fr-FR",
    trace: "on-first-retry",
  },
  webServer: process.env.CI
    ? {
        command: "NODE_ENV=production npm run build && npm run start",
        url: "http://localhost:3000",
        reuseExistingServer: false,
        timeout: 120_000,
      }
    : undefined,
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
