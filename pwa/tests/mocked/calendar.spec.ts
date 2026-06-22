import { test, expect } from "../fixtures/base.fixture";

test.describe("Date range picker in ConfigPanel", () => {
  test("shows date range picker when config panel opens", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Open config panel
    await mockedPage.getByTestId("config-open-button").click();
    // Date range trigger should be visible inside the panel
    await expect(mockedPage.getByTestId("date-range-trigger")).toBeVisible();
  });

  test("opens calendar popover on date trigger click", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Open config panel
    await mockedPage.getByTestId("config-open-button").click();
    // Click date range trigger
    await mockedPage.getByTestId("date-range-trigger").click();
    // Calendar grid should appear in the popover
    await expect(mockedPage.getByRole("grid").first()).toBeVisible();
  });

  test("clicking dates chip in summary opens config panel at dates section", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Click dates chip in summary
    await mockedPage.getByTestId("summary-dates").click();
    // Config panel should open
    const configPanel = mockedPage.locator(
      '[role="dialog"][aria-modal="true"]',
    );
    await expect(configPanel).toBeInViewport();
    // Date range trigger should be visible
    await expect(mockedPage.getByTestId("date-range-trigger")).toBeVisible();
  });

  test("clicking profile chip in summary opens config panel at pacing section", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
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
