import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent } from "../fixtures/mock-data";

test.describe("LocaleSwitcher", () => {
  test("switches locale from French to English", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());

    // Open config panel
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    const dialog = mockedPage.getByRole("dialog", { name: "Paramètres" });
    await expect(dialog).toBeInViewport();

    // Verify French locale button is active
    const englishButton = mockedPage.getByTestId("locale-switch-en");
    const frenchButton = mockedPage.getByTestId("locale-switch-fr");
    await expect(frenchButton).toHaveAttribute("aria-pressed", "true");

    // Switch to English
    await englishButton.click();

    // Dialog title should now be in English
    await expect(
      mockedPage.getByRole("dialog", { name: "Settings" }),
    ).toBeInViewport();
    await expect(englishButton).toHaveAttribute("aria-pressed", "true");
  });

  test("locale group has correct ARIA role", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());

    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();

    const group = mockedPage.getByRole("group", { name: /langue/i });
    await expect(group).toBeVisible();
  });
});
