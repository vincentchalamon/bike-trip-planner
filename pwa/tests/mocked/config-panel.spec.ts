import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent } from "../fixtures/mock-data";

test.describe("ConfigPanel", () => {
  test("opens via gear button", async ({ submitUrl, injectEvent, mockedPage }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await mockedPage.getByRole("button", { name: "Ouvrir les paramètres" }).click();
    await expect(
      mockedPage.getByRole("dialog", { name: "Paramètres" }),
    ).toBeVisible();
  });

  test("closes via ✕ button", async ({ submitUrl, injectEvent, mockedPage }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await mockedPage.getByRole("button", { name: "Ouvrir les paramètres" }).click();
    const dialog = mockedPage.getByRole("dialog", { name: "Paramètres" });
    await expect(dialog).toBeVisible();
    await mockedPage.getByRole("button", { name: "Fermer les paramètres" }).click();
    await expect(dialog).not.toBeInViewport();
  });

  test("closes via backdrop click", async ({ submitUrl, injectEvent, mockedPage }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await mockedPage.getByRole("button", { name: "Ouvrir les paramètres" }).click();
    const dialog = mockedPage.getByRole("dialog", { name: "Paramètres" });
    await expect(dialog).toBeVisible();
    // Click the backdrop (outside the panel)
    await mockedPage.mouse.click(100, 300);
    await expect(dialog).not.toBeInViewport();
  });

  test("closes via Escape key", async ({ submitUrl, injectEvent, mockedPage }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await mockedPage.getByRole("button", { name: "Ouvrir les paramètres" }).click();
    const dialog = mockedPage.getByRole("dialog", { name: "Paramètres" });
    await expect(dialog).toBeVisible();
    await mockedPage.keyboard.press("Escape");
    await expect(dialog).not.toBeInViewport();
  });

  test("toggling the last enabled accommodation type is a no-op", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await mockedPage.getByRole("button", { name: "Ouvrir les paramètres" }).click();
    await expect(
      mockedPage.getByRole("dialog", { name: "Paramètres" }),
    ).toBeVisible();

    // Disable all types except one by clicking each enabled switch
    const typeLabels = ["Hôtel", "Auberge", "Camping", "Gîte", "Chambre d'hôte", "Motel", "Refuge", "Autre"];
    // Keep only the first type enabled — disable the rest
    for (const label of typeLabels.slice(1)) {
      const switchEl = mockedPage.getByRole("switch", { name: label });
      const isChecked = await switchEl.isChecked();
      if (isChecked) {
        await switchEl.click();
      }
    }

    // Now only the first type ("Hôtel") should be enabled and disabled (can't toggle off)
    const lastSwitch = mockedPage.getByRole("switch", { name: typeLabels[0] });
    await expect(lastSwitch).toBeChecked();
    await expect(lastSwitch).toBeDisabled();

    // Clicking the disabled switch must be a no-op
    await lastSwitch.click({ force: true });
    await expect(lastSwitch).toBeChecked();
  });
});
