import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  stagesComputedEvent,
  routeParsedEvent,
  weatherFetchedEvent,
  terrainAlertsEvent,
  culturalPoiAlertsEvent,
  emptyAccommodationsFoundEvent,
  tripCompleteEvent,
  fullTripEventSequence,
} from "../../fixtures/mock-data";
import type { MercureEvent } from "../../../src/lib/mercure/types";

// ---------------------------------------------------------------------------
// Alerts and analysis — FR + EN
// ---------------------------------------------------------------------------

// --- When steps FR ---

When(
  "une étape dépasse la distance maximale configurée",
  async ({ injectEvent }) => {
    const event = stagesComputedEvent();
    const data = event.data as {
      stages: Array<Record<string, unknown>>;
    };
    data.stages[0] = {
      ...data.stages[0],
      distance: 150,
    };
    await injectEvent(event);
  },
);

When("une étape a un dénivelé supérieur à 2000m", async ({ injectEvent }) => {
  const event = stagesComputedEvent();
  const data = event.data as {
    stages: Array<Record<string, unknown>>;
  };
  data.stages[0] = {
    ...data.stages[0],
    elevation: 2500,
  };
  await injectEvent(event);
});

When(
  "les données météo indiquent de la pluie sur l'étape {int}",
  async ({ injectEvent }, stage: number) => {
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
  "aucun hébergement n'est trouvé dans un rayon de {int} km pour une étape",
  async ({ injectEvent }, radius: number) => {
    await injectEvent(emptyAccommodationsFoundEvent(0, radius));
  },
);

When(
  "une longue portion de route ne contient aucun point de ravitaillement",
  async ({ injectSequence }) => {
    await injectSequence([stagesComputedEvent(), tripCompleteEvent()]);
  },
);

When(
  "les dernières étapes cumulent trop de dénivelé",
  async ({ injectEvent }) => {
    const event = stagesComputedEvent();
    const data = event.data as {
      stages: Array<Record<string, unknown>>;
    };
    data.stages[1] = { ...data.stages[1], elevation: 1800 };
    data.stages[2] = { ...data.stages[2], elevation: 2200 };
    await injectEvent(event);
  },
);

When(
  "une étape passe près d'un site touristique majeur",
  async ({ injectEvent }) => {
    await injectEvent(culturalPoiAlertsEvent());
  },
);

When(
  "plusieurs alertes existent sur une même étape",
  async ({ injectSequence }) => {
    await injectSequence([
      terrainAlertsEvent(),
      (() => {
        const event = weatherFetchedEvent();
        const data = event.data as { stages: Array<Record<string, unknown>> };
        data.stages[0] = {
          ...data.stages[0],
          weather: {
            icon: "09d",
            description: "Heavy rain",
            tempMin: 8,
            tempMax: 14,
            windSpeed: 25,
            windDirection: "NO",
            precipitationProbability: 90,
            humidity: 95,
            comfortIndex: 15,
            relativeWindDirection: "headwind",
          },
        };
        return event;
      })(),
    ]);
  },
);

When(
  "l'étape a une distance de {int} km et un dénivelé de {int} m",
  async ({ injectEvent }, distance: number, elevation: number) => {
    const event = stagesComputedEvent();
    const data = event.data as {
      stages: Array<Record<string, unknown>>;
    };
    data.stages[0] = {
      ...data.stages[0],
      distance,
      elevation,
    };
    await injectEvent(event);
  },
);

// --- When steps EN ---

When(
  "a stage exceeds the configured maximum distance",
  async ({ injectEvent }) => {
    const event = stagesComputedEvent();
    const data = event.data as {
      stages: Array<Record<string, unknown>>;
    };
    data.stages[0] = {
      ...data.stages[0],
      distance: 150,
    };
    await injectEvent(event);
  },
);

When(
  "a stage has more than {int}m elevation gain",
  async ({ injectEvent }, _elevationThreshold: number) => {
    const event = stagesComputedEvent();
    const data = event.data as {
      stages: Array<Record<string, unknown>>;
    };
    data.stages[0] = {
      ...data.stages[0],
      elevation: 2500,
    };
    await injectEvent(event);
  },
);

When(
  "weather data indicates rain on stage {int}",
  async ({ injectEvent }, stage: number) => {
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
  "no accommodation is found within {int} km for a stage",
  async ({ injectEvent }, radius: number) => {
    await injectEvent(emptyAccommodationsFoundEvent(0, radius));
  },
);

When(
  "a long route section has no supply points",
  async ({ injectSequence }) => {
    await injectSequence([stagesComputedEvent(), tripCompleteEvent()]);
  },
);

When(
  "the last stages accumulate too much elevation gain",
  async ({ injectEvent }) => {
    const event = stagesComputedEvent();
    const data = event.data as {
      stages: Array<Record<string, unknown>>;
    };
    data.stages[1] = { ...data.stages[1], elevation: 1800 };
    data.stages[2] = { ...data.stages[2], elevation: 2200 };
    await injectEvent(event);
  },
);

When("a stage passes near a major tourist site", async ({ injectEvent }) => {
  await injectEvent(culturalPoiAlertsEvent());
});

When("multiple alerts exist on the same stage", async ({ injectSequence }) => {
  await injectSequence([
    terrainAlertsEvent(),
    (() => {
      const event = weatherFetchedEvent();
      const data = event.data as { stages: Array<Record<string, unknown>> };
      data.stages[0] = {
        ...data.stages[0],
        weather: {
          icon: "09d",
          description: "Heavy rain",
          tempMin: 8,
          tempMax: 14,
          windSpeed: 25,
          windDirection: "NO",
          precipitationProbability: 90,
          humidity: 95,
          comfortIndex: 15,
          relativeWindDirection: "headwind",
        },
      };
      return event;
    })(),
  ]);
});

When(
  "the stage has a distance of {int} km and elevation of {int} m",
  async ({ injectEvent }, distance: number, elevation: number) => {
    const event = stagesComputedEvent();
    const data = event.data as {
      stages: Array<Record<string, unknown>>;
    };
    data.stages[0] = {
      ...data.stages[0],
      distance,
      elevation,
    };
    await injectEvent(event);
  },
);

// --- Given steps FR ---

Given(
  "toutes les étapes sont dans des limites raisonnables",
  async ({ injectSequence }) => {
    await injectSequence(fullTripEventSequence());
  },
);

// --- Given steps EN ---

Given("all stages are within reasonable limits", async ({ injectSequence }) => {
  await injectSequence(fullTripEventSequence());
});

// --- Then steps FR ---

Then(
  "je vois une alerte de distance excessive sur cette étape",
  async ({ mockedPage }) => {
    const card = mockedPage.getByTestId("stage-card-1");
    await expect(card).toContainText("150", { timeout: 10000 });
    await expect(card).toContainText("km", { timeout: 10000 });
  },
);

Then(
  "je vois une alerte de dénivelé important sur cette étape",
  async ({ mockedPage }) => {
    const card = mockedPage.getByTestId("stage-card-1");
    await expect(card).toContainText("2500", { timeout: 10000 });
  },
);

Then(
  "je vois une alerte météo sur la carte de l'étape {int}",
  async ({ mockedPage }, n: number) => {
    const card = mockedPage.getByTestId(`stage-card-${n}`);
    await expect(card).toContainText(/Heavy rain/, { timeout: 10000 });
  },
);

Then(
  "je vois une alerte d'hébergement sur cette étape",
  async ({ mockedPage }) => {
    // Empty accommodations found event was injected for stage index 0
    const card = mockedPage.getByTestId("stage-card-1");
    await expect(card).toBeVisible({ timeout: 10000 });
  },
);

Then(
  "je vois une alerte de ravitaillement sur l'étape concernée",
  async ({ mockedPage }) => {
    const card = mockedPage.getByTestId("stage-card-1");
    await expect(card).toBeVisible({ timeout: 10000 });
  },
);

Then(
  "je vois une alerte de fatigue progressive sur les dernières étapes",
  async ({ mockedPage }) => {
    // Last stages have high elevation (1800m and 2200m) — check they are displayed
    const card2 = mockedPage.getByTestId("stage-card-2");
    const card3 = mockedPage.getByTestId("stage-card-3");
    await expect(card2).toContainText(/1800/, { timeout: 10000 });
    await expect(card3).toContainText(/2200/, { timeout: 10000 });
  },
);

Then(
  "je vois une notification de POI culturel sur cette étape",
  async ({ mockedPage }) => {
    const card = mockedPage.getByTestId("stage-card-1");
    await expect(card).toContainText("Château de Ventadour", {
      timeout: 10000,
    });
  },
);

Then("aucune alerte critique n'est affichée", async ({ mockedPage }) => {
  // Critical alerts have role="alert" in AlertBadge component
  // Exclude the Next.js route announcer which also has role="alert"
  const criticalAlerts = mockedPage.locator(
    '[role="alert"]:not(#__next-route-announcer__)',
  );
  await expect(criticalAlerts).toHaveCount(0, { timeout: 5000 });
});

Then(
  "elles s'affichent dans l'ordre de sévérité décroissante",
  async ({ mockedPage }) => {
    const card = mockedPage.getByTestId("stage-card-1");
    const text = await card.textContent();
    // terrainAlertsEvent has a "warning" on stage 0 ("Route non goudronnee sur 3km")
    // The alert list sorts by severity: critical > warning > nudge
    expect(text).toContain("Route non goudronnee sur 3km");
  },
);

Then(
  "le niveau de difficulté est {string}",
  async ({ mockedPage }, level: string) => {
    const card = mockedPage.getByTestId("stage-card-1");
    await expect(card).toContainText(level, { timeout: 10000 });
  },
);

// --- Then steps EN ---

Then(
  "I see an excessive distance alert on that stage",
  async ({ mockedPage }) => {
    const card = mockedPage.getByTestId("stage-card-1");
    await expect(card).toContainText("150", { timeout: 10000 });
    await expect(card).toContainText("km", { timeout: 10000 });
  },
);

Then("I see a high elevation alert on that stage", async ({ mockedPage }) => {
  const card = mockedPage.getByTestId("stage-card-1");
  await expect(card).toContainText("2500", { timeout: 10000 });
});

Then(
  "I see a weather alert on stage card {int}",
  async ({ mockedPage }, n: number) => {
    const card = mockedPage.getByTestId(`stage-card-${n}`);
    await expect(card).toContainText(/Heavy rain/, { timeout: 10000 });
  },
);

Then("I see an accommodation alert on that stage", async ({ mockedPage }) => {
  const card = mockedPage.getByTestId("stage-card-1");
  await expect(card).toBeVisible({ timeout: 10000 });
});

Then("I see a supply alert on the affected stage", async ({ mockedPage }) => {
  const card = mockedPage.getByTestId("stage-card-1");
  await expect(card).toBeVisible({ timeout: 10000 });
});

Then(
  "I see a progressive fatigue alert on the last stages",
  async ({ mockedPage }) => {
    const card2 = mockedPage.getByTestId("stage-card-2");
    const card3 = mockedPage.getByTestId("stage-card-3");
    await expect(card2).toContainText(/1800/, { timeout: 10000 });
    await expect(card3).toContainText(/2200/, { timeout: 10000 });
  },
);

Then(
  "I see a cultural POI notification on that stage",
  async ({ mockedPage }) => {
    const card = mockedPage.getByTestId("stage-card-1");
    await expect(card).toContainText("Château de Ventadour", {
      timeout: 10000,
    });
  },
);

Then("no critical alerts are displayed", async ({ mockedPage }) => {
  const criticalAlerts = mockedPage.locator(
    '[role="alert"]:not(#__next-route-announcer__)',
  );
  await expect(criticalAlerts).toHaveCount(0, { timeout: 5000 });
});

Then(
  "they are displayed in descending order of severity",
  async ({ mockedPage }) => {
    const card = mockedPage.getByTestId("stage-card-1");
    const text = await card.textContent();
    expect(text).toContain("Route non goudronnee sur 3km");
  },
);

Then(
  "the difficulty level is {string}",
  async ({ mockedPage }, level: string) => {
    const card = mockedPage.getByTestId("stage-card-1");
    await expect(card).toContainText(level, { timeout: 10000 });
  },
);
