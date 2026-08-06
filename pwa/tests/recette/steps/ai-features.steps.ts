import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripReadyEvent,
  tripReadyEventWithAiOverview,
  tripReadyEventWithStageAiAnalysis,
} from "../../fixtures/mock-data";
import type { MercureEvent } from "../../../src/lib/mercure/types";

// ---------------------------------------------------------------------------
// AI features — trip overview, stage summary, diff highlight. FR + EN. Mirrors
// the mocked specs (trip-ai-overview, stage-ai-summary, diff-highlight) but
// drives the public recette fixtures so the scenarios are executable
// end-to-end.
// ---------------------------------------------------------------------------

/** A stage_updated event changing the distance (72.5 → 55.0 km). */
function stageUpdatedWithDistanceChange(stageIndex: number): MercureEvent {
  return {
    type: "stage_updated",
    data: {
      stageIndex,
      stage: {
        dayNumber: stageIndex + 1,
        distance: 55.0,
        elevation: 720,
        elevationLoss: 640,
        startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
        endPoint: { lat: 44.5, lon: 4.4, ele: 500 },
        geometry: [
          { lat: 44.735, lon: 4.598, ele: 280 },
          { lat: 44.5, lon: 4.4, ele: 500 },
        ],
        label: null,
        isRestDay: false,
        weather: null,
        alerts: [],
        pois: [],
        accommodations: [],
        selectedAccommodation: null,
        events: [],
      },
    },
  };
}

/** A stage_updated event adding a new alert (distance unchanged). */
function stageUpdatedWithNewAlerts(stageIndex: number): MercureEvent {
  return {
    type: "stage_updated",
    data: {
      stageIndex,
      stage: {
        dayNumber: stageIndex + 1,
        distance: 72.5,
        elevation: 1180,
        elevationLoss: 920,
        startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
        endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
        geometry: [
          { lat: 44.735, lon: 4.598, ele: 280 },
          { lat: 44.532, lon: 4.392, ele: 540 },
        ],
        label: null,
        isRestDay: false,
        weather: null,
        alerts: [
          {
            type: "warning",
            message: "Newly detected steep gradient",
            lat: 44.6,
            lon: 4.5,
          },
        ],
        pois: [],
        accommodations: [],
        selectedAccommodation: null,
        events: [],
      },
    },
  };
}

// ===========================================================================
// Given — trip AI overview (FR + EN)
// ===========================================================================

Given(
  "j'ai créé un voyage avec une synthèse IA",
  async ({ submitUrl, injectEvent }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithAiOverview());
  },
);

Given(
  "I have created a trip with an AI overview",
  async ({ submitUrl, injectEvent }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithAiOverview());
  },
);

Given(
  "j'ai créé un voyage avec une synthèse IA sur mobile",
  async ({ submitUrl, injectEvent, mockedPage }) => {
    await mockedPage.setViewportSize({ width: 375, height: 800 });
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithAiOverview());
  },
);

Given(
  "I have created a trip with an AI overview on mobile",
  async ({ submitUrl, injectEvent, mockedPage }) => {
    await mockedPage.setViewportSize({ width: 375, height: 800 });
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithAiOverview());
  },
);

Given(
  "j'ai créé un voyage sans synthèse IA",
  async ({ submitUrl, injectEvent }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEvent());
  },
);

Given(
  "I have created a trip without an AI overview",
  async ({ submitUrl, injectEvent }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEvent());
  },
);

// ===========================================================================
// Given — per-stage AI analysis (FR + EN)
// ===========================================================================

Given(
  "j'ai créé un voyage avec une analyse IA par étape",
  async ({ submitUrl, injectEvent }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithStageAiAnalysis());
  },
);

Given(
  "I have created a trip with per-stage AI analysis",
  async ({ submitUrl, injectEvent }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithStageAiAnalysis());
  },
);

// ADR-043: the single-shot AI refinement card and the "Aperçu" wizard step it
// lived on were removed (Saisie -> loader -> trip view). Its Given/When/Then
// step definitions were deleted along with the scenarios.

// ===========================================================================
// When — stage AI analysis interactions (FR + EN)
// ===========================================================================

When("je déploie les détails de la synthèse IA", async ({ mockedPage }) => {
  await mockedPage.getByTestId("trip-ai-overview-toggle").click();
});

When("I expand the AI overview details", async ({ mockedPage }) => {
  await mockedPage.getByTestId("trip-ai-overview-toggle").click();
});

When(
  "je déploie les alertes complètes de l'analyse IA de l'étape {int}",
  async ({ mockedPage }, n: number) => {
    await mockedPage
      .getByTestId(`stage-ai-summary-${n - 1}`)
      .getByTestId("stage-ai-summary-alerts-show-more")
      .click();
  },
);

When(
  "I expand the full alerts of the AI analysis of stage {int}",
  async ({ mockedPage }, n: number) => {
    await mockedPage
      .getByTestId(`stage-ai-summary-${n - 1}`)
      .getByTestId("stage-ai-summary-alerts-show-more")
      .click();
  },
);

When(
  "j'applique les suggestions IA de l'étape {int}",
  async ({ mockedPage }, n: number) => {
    await mockedPage
      .getByTestId(`stage-ai-summary-${n - 1}`)
      .getByTestId("stage-ai-summary-apply")
      .click();
  },
);

When(
  "I apply the AI suggestions of stage {int}",
  async ({ mockedPage }, n: number) => {
    await mockedPage
      .getByTestId(`stage-ai-summary-${n - 1}`)
      .getByTestId("stage-ai-summary-apply")
      .click();
  },
);

// ===========================================================================
// When — diff highlight (FR + EN)
// ===========================================================================

When(
  "l'étape {int} est recalculée avec une distance modifiée",
  async ({ mockedPage, injectEvent }, n: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${n}`);
    await stageCard
      .getByRole("button", {
        name: /Sélectionner cet hébergement|Select accommodation/,
      })
      .first()
      .click();
    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });
    await injectEvent(stageUpdatedWithDistanceChange(n - 1));
    await expect(stageCard).toBeVisible({ timeout: 3000 });
  },
);

When(
  "stage {int} is recomputed with a changed distance",
  async ({ mockedPage, injectEvent }, n: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${n}`);
    await stageCard
      .getByRole("button", {
        name: /Sélectionner cet hébergement|Select accommodation/,
      })
      .first()
      .click();
    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });
    await injectEvent(stageUpdatedWithDistanceChange(n - 1));
    await expect(stageCard).toBeVisible({ timeout: 3000 });
  },
);

When(
  "l'étape {int} est recalculée avec une nouvelle alerte",
  async ({ mockedPage, injectEvent }, n: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${n}`);
    await stageCard
      .getByRole("button", {
        name: /Sélectionner cet hébergement|Select accommodation/,
      })
      .first()
      .click();
    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });
    await injectEvent(stageUpdatedWithNewAlerts(n - 1));
    await expect(stageCard).toBeVisible({ timeout: 3000 });
  },
);

When(
  "stage {int} is recomputed with a new alert",
  async ({ mockedPage, injectEvent }, n: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${n}`);
    await stageCard
      .getByRole("button", {
        name: /Sélectionner cet hébergement|Select accommodation/,
      })
      .first()
      .click();
    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });
    await injectEvent(stageUpdatedWithNewAlerts(n - 1));
    await expect(stageCard).toBeVisible({ timeout: 3000 });
  },
);

// ===========================================================================
// Then — trip AI overview (FR + EN)
// ===========================================================================

Then(
  "la carte de synthèse IA du voyage est visible",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("trip-ai-overview")).toBeVisible({
      timeout: 10000,
    });
  },
);

Then("the trip AI overview card is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("trip-ai-overview")).toBeVisible({
    timeout: 10000,
  });
});

Then("le résumé narratif IA du voyage est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("trip-ai-overview-teaser")).toBeVisible();
});

Then("the trip AI narrative summary is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("trip-ai-overview-teaser")).toBeVisible();
});

Then("les patterns globaux IA sont visibles", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("trip-ai-overview-patterns")).toBeVisible(
    {
      timeout: 10000,
    },
  );
});

Then("the AI global patterns are visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("trip-ai-overview-patterns")).toBeVisible(
    {
      timeout: 10000,
    },
  );
});

Then(
  "les recommandations IA inter-étapes sont visibles",
  async ({ mockedPage }) => {
    await expect(
      mockedPage.getByTestId("trip-ai-overview-recommendations"),
    ).toBeVisible({ timeout: 10000 });
  },
);

Then(
  "the AI cross-stage recommendations are visible",
  async ({ mockedPage }) => {
    await expect(
      mockedPage.getByTestId("trip-ai-overview-recommendations"),
    ).toBeVisible({ timeout: 10000 });
  },
);

Then("les alertes IA inter-étapes sont visibles", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByTestId("trip-ai-overview-cross-stage-alerts"),
  ).toBeVisible();
});

Then("the AI cross-stage alerts are visible", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByTestId("trip-ai-overview-cross-stage-alerts"),
  ).toBeVisible();
});

Then("les détails de la synthèse IA sont repliés", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByTestId("trip-ai-overview-toggle"),
  ).toHaveAttribute("aria-expanded", "false", { timeout: 10000 });
  await expect(mockedPage.getByTestId("trip-ai-overview-details")).toBeHidden();
});

Then("the AI overview details are collapsed", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByTestId("trip-ai-overview-toggle"),
  ).toHaveAttribute("aria-expanded", "false", { timeout: 10000 });
  await expect(mockedPage.getByTestId("trip-ai-overview-details")).toBeHidden();
});

Then("les détails de la synthèse IA sont visibles", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByTestId("trip-ai-overview-details"),
  ).toBeVisible();
});

Then("the AI overview details are visible", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByTestId("trip-ai-overview-details"),
  ).toBeVisible();
});

Then(
  "la carte de synthèse IA du voyage n'est pas visible",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });
    await expect(mockedPage.getByTestId("trip-ai-overview")).toHaveCount(0);
  },
);

Then("the trip AI overview card is not visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
    timeout: 10000,
  });
  await expect(mockedPage.getByTestId("trip-ai-overview")).toHaveCount(0);
});

// ===========================================================================
// Then — per-stage AI analysis (FR + EN)
// ===========================================================================

Then(
  "la carte d'analyse IA de l'étape {int} est visible",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage.getByTestId(`stage-ai-summary-${n - 1}`),
    ).toBeVisible({
      timeout: 10000,
    });
  },
);

Then(
  "the AI analysis card for stage {int} is visible",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage.getByTestId(`stage-ai-summary-${n - 1}`),
    ).toBeVisible({
      timeout: 10000,
    });
  },
);

Then(
  "la description IA de l'étape {int} est affichée",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-ai-summary-${n - 1}`)
        .getByTestId("stage-ai-summary-narrative"),
    ).toBeVisible({ timeout: 10000 });
  },
);

Then(
  "the AI description of stage {int} is displayed",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-ai-summary-${n - 1}`)
        .getByTestId("stage-ai-summary-narrative"),
    ).toBeVisible({ timeout: 10000 });
  },
);

Then(
  "les insights IA de l'étape {int} sont affichés",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-ai-summary-${n - 1}`)
        .getByTestId("stage-ai-summary-insights"),
    ).toBeVisible({
      timeout: 10000,
    });
  },
);

Then(
  "the AI insights of stage {int} are displayed",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-ai-summary-${n - 1}`)
        .getByTestId("stage-ai-summary-insights"),
    ).toBeVisible({
      timeout: 10000,
    });
  },
);

Then(
  "les suggestions IA de l'étape {int} sont affichées",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-ai-summary-${n - 1}`)
        .getByTestId("stage-ai-summary-suggestions"),
    ).toBeVisible({ timeout: 10000 });
  },
);

Then(
  "the AI suggestions of stage {int} are displayed",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-ai-summary-${n - 1}`)
        .getByTestId("stage-ai-summary-suggestions"),
    ).toBeVisible({ timeout: 10000 });
  },
);

Then(
  "la liste complète des alertes IA de l'étape {int} est visible",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-ai-summary-${n - 1}`)
        .getByTestId("stage-ai-summary-alerts-full"),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "the full AI alert list of stage {int} is visible",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-ai-summary-${n - 1}`)
        .getByTestId("stage-ai-summary-alerts-full"),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "la file d'attente des modifications est visible",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("modification-queue")).toBeVisible({
      timeout: 5000,
    });
  },
);

Then("the modification queue is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("modification-queue")).toBeVisible({
    timeout: 5000,
  });
});

// ===========================================================================
// Then — diff highlight (FR + EN)
// ===========================================================================

Then(
  "le surlignage de diff de la distance de l'étape {int} est visible",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-card-${n}`)
        .getByTestId("diff-highlight-distance"),
    ).toBeVisible({ timeout: 2000 });
  },
);

Then(
  "the distance diff highlight of stage {int} is visible",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-card-${n}`)
        .getByTestId("diff-highlight-distance"),
    ).toBeVisible({ timeout: 2000 });
  },
);

Then(
  "le surlignage de diff de la distance de l'étape {int} disparaît après {int} secondes",
  async ({ mockedPage }, n: number, seconds: number) => {
    await mockedPage.waitForTimeout(seconds * 1000 + 500);
    await expect(
      mockedPage
        .getByTestId(`stage-card-${n}`)
        .getByTestId("diff-highlight-distance"),
    ).toBeHidden();
  },
);

Then(
  "the distance diff highlight of stage {int} disappears after {int} seconds",
  async ({ mockedPage }, n: number, seconds: number) => {
    await mockedPage.waitForTimeout(seconds * 1000 + 500);
    await expect(
      mockedPage
        .getByTestId(`stage-card-${n}`)
        .getByTestId("diff-highlight-distance"),
    ).toBeHidden();
  },
);

Then(
  "le surlignage de diff des alertes de l'étape {int} est visible",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-card-${n}`)
        .getByTestId("diff-highlight-alerts_added"),
    ).toBeVisible({ timeout: 2000 });
  },
);

Then(
  "the alerts diff highlight of stage {int} is visible",
  async ({ mockedPage }, n: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-card-${n}`)
        .getByTestId("diff-highlight-alerts_added"),
    ).toBeVisible({ timeout: 2000 });
  },
);
