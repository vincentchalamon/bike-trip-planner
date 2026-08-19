import { expect } from "@playwright/test";
import { When, Then } from "../support/fixtures";
import type { MercureEvent } from "@btp/core/mercure";

// ---------------------------------------------------------------------------
// Diff highlight — post-recompute distance / alert highlighting. FR + EN.
// Mirrors the mocked diff-highlight spec but drives the public recette fixtures
// so the scenarios are executable end-to-end.
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
        resupply: {
          foodAtLunch: [],
          waterMorning: null,
          waterAfternoon: null,
          foodAtArrival: [],
        },
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
        resupply: {
          foodAtLunch: [],
          waterMorning: null,
          waterAfternoon: null,
          foodAtArrival: [],
        },
        accommodations: [],
        selectedAccommodation: null,
        events: [],
      },
    },
  };
}

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
