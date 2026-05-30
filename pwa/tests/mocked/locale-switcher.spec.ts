import { test, expect } from "../fixtures/base.fixture";

// Since #384 the locale switcher lives permanently in the top bar, so it is
// reachable directly on the welcome screen without opening the config panel.
test.describe("LocaleSwitcher", () => {
  test("displays locale buttons with correct active state", async ({
    mockedPage,
  }) => {
    // Verify French locale button is active (default locale)
    const englishButton = mockedPage.getByTestId("locale-switch-en");
    const frenchButton = mockedPage.getByTestId("locale-switch-fr");
    await expect(frenchButton).toHaveAttribute("aria-pressed", "true");
    await expect(englishButton).toHaveAttribute("aria-pressed", "false");
  });

  test("English button is clickable", async ({ mockedPage }) => {
    const englishButton = mockedPage.getByTestId("locale-switch-en");
    await expect(englishButton).toBeEnabled();
    // Click should not throw — the server action triggers router.refresh()
    await englishButton.click();
  });

  test("locale group has correct ARIA role", async ({ mockedPage }) => {
    const group = mockedPage.getByRole("group", { name: /langue/i });
    await expect(group).toBeVisible();
  });
});
