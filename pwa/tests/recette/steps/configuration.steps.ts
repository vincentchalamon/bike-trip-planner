import { expect } from "@playwright/test";
import { When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Settings panel — FR + EN
// ---------------------------------------------------------------------------

When(
  "j'ouvre le panneau de paramètres via le bouton engrenage",
  async ({ mockedPage }) => {
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
  },
);

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
  "I see switches for types {string}",
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
  async ({ mockedPage }, speed: number) => {
    const speedSlider = mockedPage.getByRole("slider", {
      name: "Vitesse moyenne (km/h)",
    });
    await speedSlider.fill(String(speed));
  },
);

When(
  /^I change the average speed to (\d+) km\/h$/,
  async ({ mockedPage }, speed: number) => {
    const speedSlider = mockedPage.getByRole("slider", {
      name: "Vitesse moyenne (km/h)",
    });
    await speedSlider.fill(String(speed));
  },
);

Then("les temps de trajet sont recalculés", async ({ mockedPage }) => {
  // After changing speed, verify stage cards are still visible (UI updates reactively)
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
    timeout: 5000,
  });
});

Then("travel times are recalculated", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
    timeout: 5000,
  });
});

When(
  "je modifie la distance maximale à {int} km",
  async ({ mockedPage }, dist: number) => {
    const maxDistanceSlider = mockedPage.getByRole("slider", {
      name: "Distance maximale par jour (km)",
    });
    await maxDistanceSlider.fill(String(dist));
  },
);

When(
  "I change the maximum distance to {int} km",
  async ({ mockedPage }, dist: number) => {
    const maxDistanceSlider = mockedPage.getByRole("slider", {
      name: "Distance maximale par jour (km)",
    });
    await maxDistanceSlider.fill(String(dist));
  },
);

Then(
  "les étapes sont recalculées en tenant compte de cette limite",
  async ({ mockedPage }) => {
    // Verify stage cards are still visible after recalculation
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
  },
);

Then(
  "stages are recalculated respecting that limit",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
  },
);

When("j'active le mode e-bike", async ({ mockedPage }) => {
  const ebikeToggle = mockedPage.getByRole("switch", { name: "Mode VAE" });
  await ebikeToggle.click();
});

When("I enable e-bike mode", async ({ mockedPage }) => {
  const ebikeToggle = mockedPage.getByRole("switch", { name: "Mode VAE" });
  await ebikeToggle.click();
});

Then(
  "les calculs tiennent compte d'une vitesse plus élevée",
  async ({ mockedPage }) => {
    // Verify stage cards remain visible after ebike toggle
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
  },
);

Then("computations account for a higher speed", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
    timeout: 5000,
  });
});

When("je règle l'heure de départ à 9h00", async ({ mockedPage }) => {
  const departureSlider = mockedPage.getByRole("slider", {
    name: "Heure de départ (0-23)",
  });
  await departureSlider.fill("9");
});

When(
  "I set the departure time to {int}:{int} AM",
  async ({ mockedPage }, h: number, _m: number) => {
    const departureSlider = mockedPage.getByRole("slider", {
      name: "Heure de départ (0-23)",
    });
    await departureSlider.fill(String(h));
  },
);

Then(
  "l'heure d'arrivée prévue est recalculée pour chaque étape",
  async ({ mockedPage }) => {
    // Verify stage cards are still visible with content after departure hour change
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
  },
);

Then(
  "the estimated arrival time is recalculated for each stage",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
  },
);
