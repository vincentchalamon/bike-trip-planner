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

  test("hovering a date after start shows preview range and leaving clears it", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Open config panel → date range picker → calendar popover
    await mockedPage.getByTestId("config-open-button").click();
    await mockedPage.getByTestId("date-range-trigger").click();
    await expect(mockedPage.getByRole("grid").first()).toBeVisible();

    // Find a future, non-disabled date button to use as start
    const gridCells = mockedPage
      .getByRole("grid")
      .first()
      .getByRole("gridcell")
      .filter({ hasNot: mockedPage.locator("[aria-disabled=true]") });
    const startCell = gridCells.first();
    await startCell.click();

    // Hover a date a few cells later to trigger preview range
    const laterCell = gridCells.nth(5);
    await laterCell.hover();

    // Cells between start and hovered should have the preview background
    const middleCell = gridCells.nth(3);
    await expect(middleCell).toHaveClass(/bg-brand/);

    // Move mouse outside the grid to clear preview
    await mockedPage
      .getByRole("dialog")
      .first()
      .hover({ position: { x: 5, y: 5 } });
    // Preview should be cleared — middle cell should no longer have bg-brand
    await expect(middleCell).not.toHaveClass(/bg-brand/);
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
