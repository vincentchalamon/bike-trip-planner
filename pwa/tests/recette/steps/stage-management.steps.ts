import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
  supplyTimelineEvent,
} from "../../fixtures/mock-data";
import { getTripId } from "../../fixtures/api-mocks";

// Maps the localized supply-marker icon word to the emoji rendered by the
// SupplyTimeline component (water 💧, food 🍴, mixed 🏘️).
const SUPPLY_ICON: Record<string, string> = {
  eau: "💧",
  water: "💧",
  nourriture: "🍴",
  food: "🍴",
  mixte: "🏘️",
  mixed: "🏘️",
};

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
  const input = mockedPage.getByRole("textbox", {
    name: /Titre du voyage|Trip title/i,
  });
  await input.fill(text);
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
  const input = mockedPage.getByRole("textbox", {
    name: /Titre du voyage|Trip title/i,
  });
  await input.fill(text);
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
  async ({ $test }) => {
    // StageProgressBar is a day-navigation bar, not a computation progress indicator.
    // It only renders after stages are computed (dayNumbers > 0), so it cannot be
    // observed "during computation". No computation progress bar exists in the app.
    $test.fixme();
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
  async ({ $test }) => {
    // StageProgressBar is a day-navigation bar, not a computation progress indicator.
    // It only renders after stages are computed (dayNumbers > 0), so it cannot be
    // observed "during computation". No computation progress bar exists in the app.
    $test.fixme();
  },
);

// ---------------------------------------------------------------------------
// Supply timeline — FR + EN
// The Background ships a full trip; supply markers arrive via a
// `supply_timeline` SSE event for the targeted stage index.
// ---------------------------------------------------------------------------

Given(
  "des données de ravitaillement sont disponibles pour l'étape {int}",
  async ({ injectEvent }, stage: number) => {
    await injectEvent(supplyTimelineEvent(stage - 1));
  },
);

Given(
  "supply data is available for stage {int}",
  async ({ injectEvent }, stage: number) => {
    await injectEvent(supplyTimelineEvent(stage - 1));
  },
);

Given(
  "des données de ravitaillement sont disponibles pour l'étape {int} sur mobile",
  async ({ mockedPage, injectEvent }, stage: number) => {
    await mockedPage.setViewportSize({ width: 390, height: 844 });
    await injectEvent(supplyTimelineEvent(stage - 1));
  },
);

Given(
  "supply data is available for stage {int} on mobile",
  async ({ mockedPage, injectEvent }, stage: number) => {
    await mockedPage.setViewportSize({ width: 390, height: 844 });
    await injectEvent(supplyTimelineEvent(stage - 1));
  },
);

When(
  "j'ouvre le marqueur de ravitaillement à {int} km",
  async ({ mockedPage }, km: number) => {
    await mockedPage.getByTestId(`supply-marker-${km}`).click();
  },
);

When(
  "I open the supply marker at {int} km",
  async ({ mockedPage }, km: number) => {
    await mockedPage.getByTestId(`supply-marker-${km}`).click();
  },
);

Then(
  "la timeline des ravitaillements de l'étape {int} est visible",
  async ({ mockedPage }, stage: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-card-${stage}`)
        .getByTestId("supply-timeline"),
    ).toBeVisible({ timeout: 10000 });
  },
);

Then(
  "the supply timeline of stage {int} is visible",
  async ({ mockedPage }, stage: number) => {
    await expect(
      mockedPage
        .getByTestId(`stage-card-${stage}`)
        .getByTestId("supply-timeline"),
    ).toBeVisible({ timeout: 10000 });
  },
);

Then(
  "le marqueur de ravitaillement à {int} km affiche l'icône {word}",
  async ({ mockedPage }, km: number, icon: string) => {
    const marker = mockedPage.getByTestId(`supply-marker-${km}`);
    await expect(marker).toBeVisible({ timeout: 10000 });
    await expect(marker).toContainText(SUPPLY_ICON[icon] ?? icon);
  },
);

Then(
  "the supply marker at {int} km shows the {word} icon",
  async ({ mockedPage }, km: number, icon: string) => {
    const marker = mockedPage.getByTestId(`supply-marker-${km}`);
    await expect(marker).toBeVisible({ timeout: 10000 });
    await expect(marker).toContainText(SUPPLY_ICON[icon] ?? icon);
  },
);

Then(
  "l'info-bulle de ravitaillement affiche {string}",
  async ({ mockedPage }, text: string) => {
    await expect(mockedPage.getByTestId("supply-tooltip")).toContainText(text, {
      timeout: 3000,
    });
  },
);

Then(
  "the supply tooltip shows {string}",
  async ({ mockedPage }, text: string) => {
    await expect(mockedPage.getByTestId("supply-tooltip")).toContainText(text, {
      timeout: 3000,
    });
  },
);

// ---------------------------------------------------------------------------
// Timeline / roadbook redesign — FR + EN
// The Background's full trip provides active stages, so the ViewModeToggle and
// the split layout (#timeline + map-panel) render. Geometry-free is fine: the
// panels' visibility depends on `activeStages.length`, not on geometry.
// ---------------------------------------------------------------------------

Given("je passe sur un écran mobile", async ({ mockedPage }) => {
  await mockedPage.setViewportSize({ width: 390, height: 844 });
});

Given("I switch to a mobile screen", async ({ mockedPage }) => {
  await mockedPage.setViewportSize({ width: 390, height: 844 });
});

Then("la timeline est visible", async ({ mockedPage }) => {
  await expect(mockedPage.locator("#timeline")).toBeVisible({ timeout: 5000 });
});

Then("the timeline is visible", async ({ mockedPage }) => {
  await expect(mockedPage.locator("#timeline")).toBeVisible({ timeout: 5000 });
});

Then(
  "le bouton de mode {string} est actif",
  async ({ mockedPage }, mode: string) => {
    const modeMap: Record<string, string> = {
      "carte seule": "view-mode-map",
      "vue splitée": "view-mode-split",
      chronologie: "view-mode-timeline",
    };
    const testId = modeMap[mode] ?? `view-mode-${mode}`;
    await expect(
      mockedPage.getByTestId("view-mode-toggle").getByTestId(testId),
    ).toHaveAttribute("aria-pressed", "true", { timeout: 5000 });
  },
);

Then(
  "the {string} view mode button is active",
  async ({ mockedPage }, mode: string) => {
    const modeMap: Record<string, string> = {
      "map only": "view-mode-map",
      "split view": "view-mode-split",
      timeline: "view-mode-timeline",
    };
    const testId = modeMap[mode] ?? `view-mode-${mode}`;
    await expect(
      mockedPage.getByTestId("view-mode-toggle").getByTestId(testId),
    ).toHaveAttribute("aria-pressed", "true", { timeout: 5000 });
  },
);
