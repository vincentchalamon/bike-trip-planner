import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent } from "../fixtures/mock-data";

test.describe("LocaleSwitcher", () => {
  test("displays locale buttons with correct active state", async ({
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

    // Verify French locale button is active (default locale)
    const englishButton = mockedPage.getByTestId("locale-switch-en");
    const frenchButton = mockedPage.getByTestId("locale-switch-fr");
    await expect(frenchButton).toHaveAttribute("aria-pressed", "true");
    await expect(englishButton).toHaveAttribute("aria-pressed", "false");
  });

  test("English button is clickable", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());

    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();

    const englishButton = mockedPage.getByTestId("locale-switch-en");
    await expect(englishButton).toBeEnabled();
    // Click should not throw — the server action triggers router.refresh()
    await englishButton.click();
  });

  test("persists locale choice in localStorage", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());

    // Open config panel and switch to English
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await mockedPage.getByTestId("locale-switch-en").click();

    // Verify localStorage was set
    const stored = await mockedPage.evaluate(() =>
      localStorage.getItem("locale"),
    );
    expect(stored).toBe("en");

    // Verify document.documentElement.lang was updated
    const lang = await mockedPage.evaluate(
      () => document.documentElement.lang,
    );
    expect(lang).toBe("en");

    // Verify the UI re-renders in English
    await expect(
      mockedPage.getByRole("button", { name: /open settings/i }),
    ).toBeVisible();
  });

  test("detects navigator.language on first visit", async ({
    mockedPage,
  }) => {
    // Clear any stored locale to simulate first visit
    await mockedPage.evaluate(() => localStorage.removeItem("locale"));

    // Patch navigator.language before page scripts execute
    await mockedPage.addInitScript(() => {
      Object.defineProperty(navigator, "language", {
        get: () => "en-US",
        configurable: true,
      });
    });
    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");

    // The app should detect "en" from navigator.language
    const lang = await mockedPage.evaluate(
      () => document.documentElement.lang,
    );
    expect(lang).toBe("en");
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
