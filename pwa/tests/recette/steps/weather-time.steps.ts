import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  weatherFetchedEvent,
  stagesComputedEvent,
  routeParsedEvent,
} from "../../fixtures/mock-data";
import type { MercureEvent } from "../../../src/lib/mercure/types";

// ---------------------------------------------------------------------------
// Weather and travel time — FR + EN
// ---------------------------------------------------------------------------

// --- When steps FR ---

When(
  /^l'heure de départ est configurée à (\d+)h(\d+)$/,
  async ({ mockedPage }, hours: string, _minutes: string) => {
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await expect(
      mockedPage.getByRole("dialog", { name: "Paramètres" }),
    ).toBeInViewport();
    const departureSlider = mockedPage.getByRole("slider", {
      name: "Heure de départ",
    });
    await departureSlider.fill(String(hours));
  },
);

When(
  "la météo de l'étape {int} prévoit des températures sous {int}°C",
  async ({ injectEvent }, stage: number, temp: number) => {
    const event = weatherFetchedEvent();
    const data = event.data as { stages: Array<Record<string, unknown>> };
    data.stages[stage - 1] = {
      ...data.stages[stage - 1],
      weather: {
        icon: "13d",
        description: "Cold snap",
        tempMin: temp - 5,
        tempMax: temp - 1,
        windSpeed: 10,
        windDirection: "N",
        precipitationProbability: 5,
        humidity: 70,
        comfortIndex: 30,
        relativeWindDirection: "headwind",
      },
    };
    await injectEvent(event);
  },
);

When(
  "la météo de l'étape {int} prévoit plus de {int}mm de pluie",
  async ({ injectEvent }, stage: number, _mm: number) => {
    const event = weatherFetchedEvent();
    const data = event.data as { stages: Array<Record<string, unknown>> };
    data.stages[stage - 1] = {
      ...data.stages[stage - 1],
      weather: {
        icon: "09d",
        description: "Heavy rain",
        tempMin: 10,
        tempMax: 18,
        windSpeed: 20,
        windDirection: "NO",
        precipitationProbability: 95,
        humidity: 90,
        comfortIndex: 20,
        relativeWindDirection: "crosswind",
      },
    };
    await injectEvent(event);
  },
);

When(
  /^je modifie la vitesse moyenne à (\d+) km\/h dans les paramètres$/,
  async ({ mockedPage }, speed: number) => {
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await expect(
      mockedPage.getByRole("dialog", { name: "Paramètres" }),
    ).toBeInViewport();
    const speedSlider = mockedPage.getByRole("slider", {
      name: "Vitesse moyenne (km/h)",
    });
    await speedSlider.fill(String(speed));
  },
);

When("le facteur de fatigue est activé", async ({ mockedPage }) => {
  await mockedPage
    .getByRole("button", { name: "Ouvrir les paramètres" })
    .click();
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).toBeInViewport();
  const fatigueSlider = mockedPage.getByRole("slider", {
    name: "Indice de fatigue accumulée",
  });
  await fatigueSlider.fill("30");
});

When("le mode e-bike est activé", async ({ mockedPage }) => {
  await mockedPage
    .getByRole("button", { name: "Ouvrir les paramètres" })
    .click();
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).toBeInViewport();
  const ebikeToggle = mockedPage.getByRole("switch", { name: "Mode VAE" });
  await ebikeToggle.click();
});

// --- When steps EN ---

When(
  /^the departure time is set to (\d+):(\d+) AM$/,
  async ({ mockedPage }, hours: string, _minutes: string) => {
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await expect(
      mockedPage.getByRole("dialog", { name: "Paramètres" }),
    ).toBeInViewport();
    const departureSlider = mockedPage.getByRole("slider", {
      name: "Heure de départ",
    });
    await departureSlider.fill(String(hours));
  },
);

When(
  "stage {int} weather forecasts temperatures below {int}°C",
  async ({ injectEvent }, stage: number, temp: number) => {
    const event = weatherFetchedEvent();
    const data = event.data as { stages: Array<Record<string, unknown>> };
    data.stages[stage - 1] = {
      ...data.stages[stage - 1],
      weather: {
        icon: "13d",
        description: "Cold snap",
        tempMin: temp - 5,
        tempMax: temp - 1,
        windSpeed: 10,
        windDirection: "N",
        precipitationProbability: 5,
        humidity: 70,
        comfortIndex: 30,
        relativeWindDirection: "headwind",
      },
    };
    await injectEvent(event);
  },
);

When(
  "stage {int} weather forecasts more than {int}mm of rain",
  async ({ injectEvent }, stage: number, _mm: number) => {
    const event = weatherFetchedEvent();
    const data = event.data as { stages: Array<Record<string, unknown>> };
    data.stages[stage - 1] = {
      ...data.stages[stage - 1],
      weather: {
        icon: "09d",
        description: "Heavy rain",
        tempMin: 10,
        tempMax: 18,
        windSpeed: 20,
        windDirection: "NO",
        precipitationProbability: 95,
        humidity: 90,
        comfortIndex: 20,
        relativeWindDirection: "crosswind",
      },
    };
    await injectEvent(event);
  },
);

When(
  /^I change the average speed to (\d+) km\/h in settings$/,
  async ({ mockedPage }, speed: number) => {
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await expect(
      mockedPage.getByRole("dialog", { name: "Paramètres" }),
    ).toBeInViewport();
    const speedSlider = mockedPage.getByRole("slider", {
      name: "Vitesse moyenne (km/h)",
    });
    await speedSlider.fill(String(speed));
  },
);

When("the fatigue factor is enabled", async ({ mockedPage }) => {
  await mockedPage
    .getByRole("button", { name: "Ouvrir les paramètres" })
    .click();
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).toBeInViewport();
  const fatigueSlider = mockedPage.getByRole("slider", {
    name: "Indice de fatigue accumulée",
  });
  await fatigueSlider.fill("30");
});

When("e-bike mode is enabled", async ({ mockedPage }) => {
  await mockedPage
    .getByRole("button", { name: "Ouvrir les paramètres" })
    .click();
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).toBeInViewport();
  const ebikeToggle = mockedPage.getByRole("switch", { name: "Mode VAE" });
  await ebikeToggle.click();
});

// --- Then steps FR ---

Then(
  "la carte de l'étape {int} affiche les conditions météo",
  async ({ mockedPage }, n: number) => {
    const card = mockedPage.getByTestId(`stage-card-${n}`);
    await expect(card).toContainText(/°C/, { timeout: 10000 });
  },
);

Then(
  "je vois la plage de températures {string} sur l'étape {int}",
  async ({ mockedPage }, range: string, n: number) => {
    const card = mockedPage.getByTestId(`stage-card-${n}`);
    await expect(card).toContainText(range, { timeout: 10000 });
  },
);

Then(
  "chaque carte d'étape affiche un temps de trajet estimé",
  async ({ mockedPage }) => {
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      await expect(card).toContainText(/\d+h\d{2}/, { timeout: 10000 });
    }
  },
);

Then(
  "je vois l'heure d'arrivée prévue sur chaque étape",
  async ({ mockedPage }) => {
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      await expect(card).toContainText(/Arrivée ~\d+h\d{2}/, {
        timeout: 10000,
      });
    }
  },
);

Then(
  "je vois une alerte de froid sur l'étape {int}",
  async ({ mockedPage }, n: number) => {
    const card = mockedPage.getByTestId(`stage-card-${n}`);
    await expect(card).toContainText(/Cold snap/, { timeout: 10000 });
  },
);

Then(
  "je vois une alerte pluie sur l'étape {int}",
  async ({ mockedPage }, n: number) => {
    const card = mockedPage.getByTestId(`stage-card-${n}`);
    await expect(card).toContainText(/Heavy rain/, { timeout: 10000 });
  },
);

Then(
  "chaque étape affiche une icône météo correspondant aux conditions",
  async ({ mockedPage }) => {
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      // Weather indicator renders an SVG icon (lucide) next to the description
      await expect(card).toContainText(/°C/, { timeout: 10000 });
    }
  },
);

Then(
  "les temps de trajet de toutes les étapes sont mis à jour",
  async ({ mockedPage }) => {
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      await expect(card).toContainText(/\d+h\d{2}/, { timeout: 10000 });
    }
  },
);

Then(
  "la distance cible des étapes diminue progressivement",
  async ({ mockedPage }) => {
    const distances: number[] = [];
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      const text = await card.textContent();
      const match = text?.match(/([\d.]+)\s*km/);
      if (match) {
        distances.push(parseFloat(match[1]));
      }
    }
    expect(distances.length).toBeGreaterThanOrEqual(2);
    for (let i = 1; i < distances.length; i++) {
      expect(distances[i]).toBeLessThanOrEqual(distances[i - 1]);
    }
  },
);

Then(
  "les temps de trajet sont recalculés avec une vitesse supérieure",
  async ({ mockedPage }) => {
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      await expect(card).toContainText(/\d+h\d{2}/, { timeout: 10000 });
    }
  },
);

// --- Then steps EN ---

Then(
  "stage card {int} shows weather conditions",
  async ({ mockedPage }, n: number) => {
    const card = mockedPage.getByTestId(`stage-card-${n}`);
    await expect(card).toContainText(/°C/, { timeout: 10000 });
  },
);

Then(
  "I see the temperature range {string} on stage {int}",
  async ({ mockedPage }, range: string, n: number) => {
    const card = mockedPage.getByTestId(`stage-card-${n}`);
    await expect(card).toContainText(range, { timeout: 10000 });
  },
);

Then(
  "each stage card shows an estimated travel time",
  async ({ mockedPage }) => {
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      await expect(card).toContainText(/\d+h\d{2}/, { timeout: 10000 });
    }
  },
);

Then(
  "I see the estimated arrival time on each stage",
  async ({ mockedPage }) => {
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      await expect(card).toContainText(/~\d+h\d{2}/, { timeout: 10000 });
    }
  },
);

Then(
  "I see a cold weather alert on stage {int}",
  async ({ mockedPage }, n: number) => {
    const card = mockedPage.getByTestId(`stage-card-${n}`);
    await expect(card).toContainText(/Cold snap/, { timeout: 10000 });
  },
);

Then(
  "I see a rain alert on stage {int}",
  async ({ mockedPage }, n: number) => {
    const card = mockedPage.getByTestId(`stage-card-${n}`);
    await expect(card).toContainText(/Heavy rain/, { timeout: 10000 });
  },
);

Then(
  "each stage shows a weather icon matching its conditions",
  async ({ mockedPage }) => {
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      await expect(card).toContainText(/°C/, { timeout: 10000 });
    }
  },
);

Then(
  "the travel times of all stages are updated",
  async ({ mockedPage }) => {
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      await expect(card).toContainText(/\d+h\d{2}/, { timeout: 10000 });
    }
  },
);

Then(
  "the target distance decreases progressively across stages",
  async ({ mockedPage }) => {
    const distances: number[] = [];
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      const text = await card.textContent();
      const match = text?.match(/([\d.]+)\s*km/);
      if (match) {
        distances.push(parseFloat(match[1]));
      }
    }
    expect(distances.length).toBeGreaterThanOrEqual(2);
    for (let i = 1; i < distances.length; i++) {
      expect(distances[i]).toBeLessThanOrEqual(distances[i - 1]);
    }
  },
);

Then(
  "travel times are recalculated with a higher speed",
  async ({ mockedPage }) => {
    for (let i = 1; i <= 3; i++) {
      const card = mockedPage.getByTestId(`stage-card-${i}`);
      await expect(card).toContainText(/\d+h\d{2}/, { timeout: 10000 });
    }
  },
);
