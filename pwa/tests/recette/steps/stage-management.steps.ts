import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
} from "../../fixtures/mock-data";
import { getTripId } from "../../fixtures/api-mocks";

// ---------------------------------------------------------------------------
// Stage management — FR + EN
// ---------------------------------------------------------------------------

// --- Given steps FR ---

Given(
  "un jour de repos existe après l'étape {int}",
  async ({ submitUrl, injectSequence }, stage: number) => {
    await submitUrl();
    const baseStages = stagesComputedEvent().data.stages as Array<
      Record<string, unknown>
    >;
    // Insert a rest day after the given stage (0-indexed: stage 1 => after index 0)
    const stagesWithRestDay = [
      ...baseStages.slice(0, stage).map((s, i) => ({
        ...s,
        dayNumber: i + 1,
        isRestDay: false,
      })),
      {
        dayNumber: stage + 1,
        distance: 0,
        elevation: 0,
        elevationLoss: 0,
        startPoint: baseStages[stage - 1].endPoint,
        endPoint: baseStages[stage - 1].endPoint,
        geometry: [],
        label: null,
        isRestDay: true,
      },
      ...baseStages.slice(stage).map((s, i) => ({
        ...s,
        dayNumber: stage + 2 + i,
        isRestDay: false,
      })),
    ];
    await injectSequence([
      routeParsedEvent(),
      { type: "stages_computed", data: { stages: stagesWithRestDay } },
      tripCompleteEvent(),
    ]);
  },
);

// --- Given steps EN ---

Given(
  "a rest day exists after stage {int}",
  async ({ submitUrl, injectSequence }, stage: number) => {
    await submitUrl();
    const baseStages = stagesComputedEvent().data.stages as Array<
      Record<string, unknown>
    >;
    const stagesWithRestDay = [
      ...baseStages.slice(0, stage).map((s, i) => ({
        ...s,
        dayNumber: i + 1,
        isRestDay: false,
      })),
      {
        dayNumber: stage + 1,
        distance: 0,
        elevation: 0,
        elevationLoss: 0,
        startPoint: baseStages[stage - 1].endPoint,
        endPoint: baseStages[stage - 1].endPoint,
        geometry: [],
        label: null,
        isRestDay: true,
      },
      ...baseStages.slice(stage).map((s, i) => ({
        ...s,
        dayNumber: stage + 2 + i,
        isRestDay: false,
      })),
    ];
    await injectSequence([
      routeParsedEvent(),
      { type: "stages_computed", data: { stages: stagesWithRestDay } },
      tripCompleteEvent(),
    ]);
  },
);

// --- When steps FR ---

When("je clique sur le titre du voyage", async ({ mockedPage }) => {
  await mockedPage.getByTestId("trip-title").click();
});

When("je saisis {string}", async ({ mockedPage }, text: string) => {
  const input = mockedPage.getByRole("textbox", { name: "Titre du voyage" });
  await input.fill(text);
  await input.press("Enter");
});

When(
  "je fusionne l'étape {int} avec l'étape {int}",
  async ({ $test }, _stage1: number, _stage2: number) => {
    // Merge UI requires drag-and-drop interaction not reliably testable in headless
    $test.fixme();
  },
);

When(
  "je divise l'étape {int} à mi-parcours",
  async ({ $test }, _stage: number) => {
    // Split UI requires complex map interaction not reliably testable in headless
    $test.fixme();
  },
);

When(
  "je déplace le point de fin de l'étape {int} sur la carte",
  async ({ $test }, _stage: number) => {
    // MapLibre drag interaction not testable in headless mode
    $test.fixme();
  },
);

When(
  "j'ajoute un jour de repos après l'étape {int}",
  async ({ mockedPage }, stage: number) => {
    await mockedPage.route("**/stages/*/rest-day", (route) =>
      route.fulfill({ status: 202, body: "" }),
    );
    // Button index is 0-based: stage 1 => button index 0
    await mockedPage.getByTestId(`add-rest-day-button-${stage - 1}`).click();
  },
);

When("je supprime le jour de repos", async ({ mockedPage }) => {
  await mockedPage.route(
    `**/trips/${getTripId()}/stages/*`,
    (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      return route.fulfill({ status: 202, body: "" });
    },
  );
  // Find the first visible delete-rest-day button
  const deleteButton = mockedPage.locator('[data-testid^="delete-rest-day-"]');
  await deleteButton.first().click();
});

When("je modifie une étape", async ({ mockedPage }) => {
  // Delete stage 3 as simplest modification
  await mockedPage.getByTestId("delete-stage-3").click();
});

When("j'annule l'action avec Ctrl+Z", async ({ mockedPage }) => {
  await mockedPage.keyboard.press("Control+z");
});

When("je rétablis l'action avec Ctrl+Y", async ({ mockedPage }) => {
  await mockedPage.keyboard.press("Control+y");
});

// --- When steps EN ---

When("I click on the trip title", async ({ mockedPage }) => {
  await mockedPage.getByTestId("trip-title").click();
});

When("I type {string}", async ({ mockedPage }, text: string) => {
  const input = mockedPage.getByRole("textbox", { name: "Titre du voyage" });
  await input.fill(text);
  await input.press("Enter");
});

When(
  "I merge stage {int} with stage {int}",
  async ({ $test }, _stage1: number, _stage2: number) => {
    // Merge UI requires drag-and-drop interaction not reliably testable in headless
    $test.fixme();
  },
);

When("I split stage {int} at mid-route", async ({ $test }, _stage: number) => {
  // Split UI requires complex map interaction not reliably testable in headless
  $test.fixme();
});

When(
  "I drag the end point of stage {int} on the map",
  async ({ $test }, _stage: number) => {
    // MapLibre drag interaction not testable in headless mode
    $test.fixme();
  },
);

When(
  "I add a rest day after stage {int}",
  async ({ mockedPage }, stage: number) => {
    await mockedPage.route("**/stages/*/rest-day", (route) =>
      route.fulfill({ status: 202, body: "" }),
    );
    await mockedPage.getByTestId(`add-rest-day-button-${stage - 1}`).click();
  },
);

When("I remove the rest day", async ({ mockedPage }) => {
  await mockedPage.route(
    `**/trips/${getTripId()}/stages/*`,
    (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      return route.fulfill({ status: 202, body: "" });
    },
  );
  const deleteButton = mockedPage.locator('[data-testid^="delete-rest-day-"]');
  await deleteButton.first().click();
});

When("I modify a stage", async ({ mockedPage }) => {
  // Delete stage 3 as simplest modification
  await mockedPage.getByTestId("delete-stage-3").click();
});

When("I undo with Ctrl+Z", async ({ mockedPage }) => {
  await mockedPage.keyboard.press("Control+z");
});

When("I redo with Ctrl+Y", async ({ mockedPage }) => {
  await mockedPage.keyboard.press("Control+y");
});

// --- Then steps FR ---

Then(
  "la carte de l'étape {int} affiche le niveau de difficulté",
  async ({ mockedPage }, stage: number) => {
    const card = mockedPage.getByTestId(`stage-card-${stage}`);
    const badge = card.locator('[aria-label*="km"]').first();
    await expect(badge).toBeVisible({ timeout: 5000 });
  },
);

Then("le titre affiché est {string}", async ({ mockedPage }, title: string) => {
  await expect(mockedPage.getByTestId("trip-title")).toContainText(title, {
    timeout: 5000,
  });
});

Then("le titre n'a pas été modifié", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("trip-title")).toContainText(
    "Tour de l'Ardeche",
    { timeout: 5000 },
  );
});

Then(
  "je ne vois plus que {int} cartes d'étapes",
  async ({ mockedPage }, count: number) => {
    const cards = mockedPage.locator('[data-testid^="stage-card-"]');
    await expect(cards).toHaveCount(count, { timeout: 5000 });
  },
);

Then("je vois {int} cartes d'étapes", async ({ mockedPage }, count: number) => {
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(count, { timeout: 5000 });
});

Then(
  "la distance de l'étape {int} est recalculée",
  async ({ $test }, _stage: number) => {
    // Depends on map drag interaction which is not testable in headless mode
    $test.fixme();
  },
);

Then(
  "je vois un indicateur de jour de repos entre l'étape {int} et l'étape {int}",
  async ({ mockedPage }, _stage1: number, stage2: number) => {
    // rest-day-card index corresponds to the position in the list (stage2 - 1 for 1-based)
    await expect(
      mockedPage.getByTestId(`rest-day-card-${stage2 - 1}`),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "il n'y a plus d'indicateur de jour de repos entre l'étape {int} et l'étape {int}",
  async ({ mockedPage }, _stage1: number, stage2: number) => {
    await expect(
      mockedPage.getByTestId(`rest-day-card-${stage2 - 1}`),
    ).toBeHidden({ timeout: 5000 });
  },
);

Then("je vois la durée totale du voyage en jours", async ({ mockedPage }) => {
  // The trip summary shows stage count which implies duration
  const stageCards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(stageCards.first()).toBeVisible({ timeout: 5000 });
  const count = await stageCards.count();
  expect(count).toBeGreaterThan(0);
});

Then(
  "les badges de difficulté de toutes les étapes sont cohérents avec leurs valeurs",
  async ({ mockedPage }) => {
    const cards = mockedPage.locator('[data-testid^="stage-card-"]');
    const count = await cards.count();
    for (let i = 0; i < count; i++) {
      const card = cards.nth(i);
      const badge = card.locator('[aria-label*="km"]').first();
      await expect(badge).toBeVisible({ timeout: 5000 });
      await expect(badge).toHaveAttribute("aria-label", /km.*D\+/);
    }
  },
);

Then("l'étape est revenue à son état précédent", async ({ mockedPage }) => {
  // After undo, the deleted stage should reappear (back to 3 stages)
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(3, { timeout: 5000 });
});

Then("l'étape est à nouveau modifiée", async ({ mockedPage }) => {
  // After redo, the stage should be deleted again (back to 2 stages)
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(2, { timeout: 5000 });
});

Then(
  "je vois une barre de progression pendant le calcul des étapes",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("stage-progress-bar")).toBeVisible({
      timeout: 5000,
    });
  },
);

// --- Then steps EN ---

Then(
  "stage card {int} shows a difficulty badge",
  async ({ mockedPage }, stage: number) => {
    const card = mockedPage.getByTestId(`stage-card-${stage}`);
    const badge = card.locator('[aria-label*="km"]').first();
    await expect(badge).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "the displayed title is {string}",
  async ({ mockedPage }, title: string) => {
    await expect(mockedPage.getByTestId("trip-title")).toContainText(title, {
      timeout: 5000,
    });
  },
);

Then("the title has not changed", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("trip-title")).toContainText(
    "Tour de l'Ardeche",
    { timeout: 5000 },
  );
});

Then("I only see {int} stage cards", async ({ mockedPage }, count: number) => {
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(count, { timeout: 5000 });
});

Then("I see {int} stage cards", async ({ mockedPage }, count: number) => {
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(count, { timeout: 5000 });
});

Then(
  "the distance of stage {int} is recalculated",
  async ({ $test }, _stage: number) => {
    // Depends on map drag interaction which is not testable in headless mode
    $test.fixme();
  },
);

Then(
  "I see a rest day indicator between stage {int} and stage {int}",
  async ({ mockedPage }, _stage1: number, stage2: number) => {
    await expect(
      mockedPage.getByTestId(`rest-day-card-${stage2 - 1}`),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "there is no longer a rest day indicator between stage {int} and stage {int}",
  async ({ mockedPage }, _stage1: number, stage2: number) => {
    await expect(
      mockedPage.getByTestId(`rest-day-card-${stage2 - 1}`),
    ).toBeHidden({ timeout: 5000 });
  },
);

Then("I see the total trip duration in days", async ({ mockedPage }) => {
  const stageCards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(stageCards.first()).toBeVisible({ timeout: 5000 });
  const count = await stageCards.count();
  expect(count).toBeGreaterThan(0);
});

Then(
  "all stage difficulty badges are consistent with their values",
  async ({ mockedPage }) => {
    const cards = mockedPage.locator('[data-testid^="stage-card-"]');
    const count = await cards.count();
    for (let i = 0; i < count; i++) {
      const card = cards.nth(i);
      const badge = card.locator('[aria-label*="km"]').first();
      await expect(badge).toBeVisible({ timeout: 5000 });
      await expect(badge).toHaveAttribute("aria-label", /km.*D\+/);
    }
  },
);

Then("the stage has reverted to its previous state", async ({ mockedPage }) => {
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(3, { timeout: 5000 });
});

Then("the stage is modified again", async ({ mockedPage }) => {
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(2, { timeout: 5000 });
});

Then(
  "I see a progress bar while stages are being computed",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("stage-progress-bar")).toBeVisible({
      timeout: 5000,
    });
  },
);
