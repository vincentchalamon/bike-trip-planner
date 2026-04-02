import { expect } from "@playwright/test";
import { When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Settings panel — FR + EN
// ---------------------------------------------------------------------------

When("je clique sur le bouton {string}", async ({ mockedPage }, name: string) => {
  await mockedPage.getByRole("button", { name }).click();
});

When("j'ouvre le panneau de paramètres via le bouton engrenage", async ({ mockedPage }) => {
  await mockedPage.getByRole("button", { name: "Ouvrir les paramètres" }).click();
});

When("je clique en dehors du panneau", async ({ mockedPage }) => {
  await mockedPage.mouse.click(100, 300);
});

When("I click outside the panel", async ({ mockedPage }) => {
  await mockedPage.mouse.click(100, 300);
});

When(
  "je désactive tous les types d'hébergement sauf le dernier",
  async ({ mockedPage }) => {
    const typeLabels = [
      "Hôtel",
      "Auberge",
      "Camping",
      "Gîte",
      "Chambre d'hôte",
      "Motel",
      "Refuge",
    ];
    for (const label of typeLabels.slice(1)) {
      const switchEl = mockedPage.getByRole("switch", { name: label });
      const isChecked = await switchEl.isChecked();
      if (isChecked) {
        await switchEl.click();
      }
    }
  },
);

When(
  "I disable all accommodation types except the last",
  async ({ mockedPage }) => {
    const typeLabels = [
      "Hôtel",
      "Auberge",
      "Camping",
      "Gîte",
      "Chambre d'hôte",
      "Motel",
      "Refuge",
    ];
    for (const label of typeLabels.slice(1)) {
      const switchEl = mockedPage.getByRole("switch", { name: label });
      const isChecked = await switchEl.isChecked();
      if (isChecked) {
        await switchEl.click();
      }
    }
  },
);

Then(
  "je vois les interrupteurs pour les types {string}",
  async ({ mockedPage }, typesStr: string) => {
    const types = typesStr.split('", "').map((t) => t.replace(/^"|"$/g, "").trim());
    for (const label of types) {
      await expect(
        mockedPage.getByRole("switch", { name: label }),
      ).toBeVisible();
    }
  },
);

Then(
  'I see switches for types {string}',
  async ({ mockedPage }, typesStr: string) => {
    const types = typesStr.split('", "').map((t) => t.replace(/^"|"$/g, "").trim());
    for (const label of types) {
      await expect(
        mockedPage.getByRole("switch", { name: label }),
      ).toBeVisible();
    }
  },
);

Then(
  "le dernier interrupteur est désactivé et ne peut pas être modifié",
  async ({ mockedPage }) => {
    const lastSwitch = mockedPage.getByRole("switch", { name: "Hôtel" });
    await expect(lastSwitch).toBeChecked();
    await expect(lastSwitch).toBeDisabled();
  },
);

Then(
  "the last switch is disabled and cannot be toggled",
  async ({ mockedPage }) => {
    const lastSwitch = mockedPage.getByRole("switch", { name: "Hôtel" });
    await expect(lastSwitch).toBeChecked();
    await expect(lastSwitch).toBeDisabled();
  },
);
