import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent } from "../fixtures/mock-data";

test.describe("Date range picker in ConfigPanel", () => {
  test("shows date range picker when config panel opens", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Open config panel
    await mockedPage.getByTestId("config-open-button").click();
    // Date range trigger should be visible inside the panel
    await expect(
      mockedPage.getByTestId("date-range-trigger"),
    ).toBeVisible();
  });

  test("opens calendar popover on date trigger click", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Open config panel
    await mockedPage.getByTestId("config-open-button").click();
    // Click date range trigger
    await mockedPage.getByTestId("date-range-trigger").click();
    // Calendar grid should appear in the popover
    await expect(
      mockedPage.getByRole("grid").first(),
    ).toBeVisible();
  });

  test("clicking dates chip in summary opens config panel at dates section", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Click dates chip in summary
    await mockedPage.getByTestId("summary-dates").click();
    // Config panel should open
    const configPanel = mockedPage.locator(
      '[role="dialog"][aria-modal="true"]',
    );
    await expect(configPanel).toBeInViewport();
    // Date range trigger should be visible
    await expect(
      mockedPage.getByTestId("date-range-trigger"),
    ).toBeVisible();
  });

  test("selecting start then end date auto-closes popover", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Open config panel → date range picker → calendar popover
    await mockedPage.getByTestId("config-open-button").click();
    await mockedPage.getByTestId("date-range-trigger").click();
    const grid = mockedPage.getByRole("grid").first();
    await expect(grid).toBeVisible();

    // Click first enabled cell (start date)
    const enabledCells = grid.locator(
      'button[role="gridcell"]:not([disabled])',
    );
    await enabledCells.first().click();
    // Grid should still be visible (waiting for end date)
    await expect(grid).toBeVisible();

    // Click a later cell (end date) — popover should auto-close
    await enabledCells.nth(5).click();
    await expect(grid).not.toBeVisible({ timeout: 3000 });
  });

  test("clicking profile chip in summary opens config panel at pacing section", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Click profile chip in summary
    await mockedPage.getByTestId("summary-profile").click();
    // Config panel should open
    const configPanel = mockedPage.locator(
      '[role="dialog"][aria-modal="true"]',
    );
    await expect(configPanel).toBeInViewport();
    // Pacing section heading should be visible
    await expect(
      mockedPage.getByRole("heading", { name: /profil cyclo/i }),
    ).toBeVisible();
  });
});
