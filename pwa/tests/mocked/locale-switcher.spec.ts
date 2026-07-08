import { test, expect } from "../fixtures/base.fixture";

// Since #384 the locale switcher lives permanently in the top bar, so it is
// reachable directly on the welcome screen without opening the config panel.
// Since #831 it is a compact <Select> (trigger + fr/en options) rather than
// two pill buttons: the trigger carries `data-testid="locale-switch"` and a
// `data-locale` attribute reflecting the active locale; the options keep the
// `locale-switch-{fr,en}` testids but only render while the select is open.
test.describe("LocaleSwitcher", () => {
  test("trigger reflects the active locale", async ({ mockedPage }) => {
    const trigger = mockedPage.getByTestId("locale-switch");
    await expect(trigger).toBeVisible();
    // French is the default locale.
    await expect(trigger).toHaveAttribute("data-locale", "fr");
  });

  test("switching to English updates the active locale", async ({
    mockedPage,
  }) => {
    const trigger = mockedPage.getByTestId("locale-switch");
    await trigger.click();
    await mockedPage.getByTestId("locale-switch-en").click();
    await mockedPage.waitForLoadState("networkidle");
    await expect(mockedPage.getByTestId("locale-switch")).toHaveAttribute(
      "data-locale",
      "en",
    );
  });

  test("switcher exposes an accessible language label", async ({
    mockedPage,
  }) => {
    await expect(
      mockedPage.getByRole("combobox", { name: /langue/i }),
    ).toBeVisible();
  });
});
