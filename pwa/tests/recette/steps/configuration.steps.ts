import { expect } from "@playwright/test";
import { When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Settings panel — FR + EN
// ---------------------------------------------------------------------------

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
    const types = typesStr.split(", ").map((t) => t.trim());
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
    const types = typesStr.split(", ").map((t) => t.trim());
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

// --- Additional missing steps ---
// "je suis sur la page du voyage avec les étapes calculées" / "I am on the trip page with computed stages" defined in common.steps.ts

When(
  /^je modifie la vitesse moyenne à (\d+) km\/h$/,
  async ({ $test }, _speed: number) => {
    $test.fixme();
  },
);

When(
  /^I change the average speed to (\d+) km\/h$/,
  async ({ $test }, _speed: number) => {
    $test.fixme();
  },
);

Then("les temps de trajet sont recalculés", async () => {});

Then("travel times are recalculated", async () => {});

When(
  "je modifie la distance maximale à {int} km",
  async ({ $test }, _dist: number) => {
    $test.fixme();
  },
);

When(
  "I change the maximum distance to {int} km",
  async ({ $test }, _dist: number) => {
    $test.fixme();
  },
);

Then("les étapes sont recalculées en tenant compte de cette limite", async () => {});

Then("stages are recalculated respecting that limit", async () => {});

When(
  "j'active le mode e-bike",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I enable e-bike mode",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("les calculs tiennent compte d'une vitesse plus élevée", async () => {});

Then("computations account for a higher speed", async () => {});

When(
  "je règle l'heure de départ à 9h00",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I set the departure time to {int}:{int} AM",
  async ({ $test }, _h: number, _m: number) => {
    $test.fixme();
  },
);

Then("l'heure d'arrivée prévue est recalculée pour chaque étape", async () => {});

Then("the estimated arrival time is recalculated for each stage", async () => {});
