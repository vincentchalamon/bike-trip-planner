import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  routeParsedEvent,
  stagesComputedEvent,
} from "../../fixtures/mock-data";
import {
  getTrackedStageExportRequests,
  getTrackedStageFitRequests,
  getTrackedStageGpxRequests,
  getTrackedTripGpxRequests,
  trackStageExportDownload,
  trackStageFitDownload,
  trackStageGpxDownload,
} from "../support/export-download-tracker";

// ---------------------------------------------------------------------------
// Export GPX and FIT — FR + EN
// ---------------------------------------------------------------------------

// --- When steps FR ---

When(
  'je clique sur "Télécharger le GPX" de l\'étape {int}',
  async ({ mockedPage }, stage: number) => {
    await trackStageGpxDownload(mockedPage);
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const gpxButton = stageCard.getByRole("button", {
      name: /Télécharger le GPX/,
    });
    await gpxButton.click();
  },
);

When("je sélectionne un fichier GPX valide", async ({ $test }) => {
  // $test.fixme: file upload requires a real GPX file fixture — part of @fixme scenario
  $test.fixme();
});

When("je tente d'importer un fichier non-GPX", async ({ $test }) => {
  // $test.fixme: file upload requires a real file fixture — part of @fixme scenario
  $test.fixme();
});

When(
  'je clique sur "Télécharger le FIT" de l\'étape {int}',
  async ({ mockedPage }, stage: number) => {
    await trackStageFitDownload(mockedPage);
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const fitButton = stageCard.getByRole("button", {
      name: /Télécharger le FIT/,
    });
    await fitButton.click();
  },
);

When(
  "le calcul des étapes est en cours",
  async ({ submitUrl, injectSequence }) => {
    // Inject route_parsed + stages_computed but NOT tripCompleteEvent (computation still running)
    await submitUrl();
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
  },
);

When(
  "je clique sur le bouton export de format {string} de l'étape {int}",
  async ({ mockedPage }, format: string, stage: number) => {
    await trackStageExportDownload(mockedPage, format.toLowerCase());
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const exportButton = stageCard.getByRole("button", {
      name: new RegExp(format, "i"),
    });
    await exportButton.click();
  },
);

// --- When steps EN ---

When(
  'I click "Download GPX" for stage {int}',
  async ({ mockedPage }, stage: number) => {
    await trackStageGpxDownload(mockedPage);
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const gpxButton = stageCard.getByRole("button", {
      name: /Télécharger le GPX/,
    });
    await gpxButton.click();
  },
);

When("I select a valid GPX file", async ({ $test }) => {
  // $test.fixme: file upload requires a real GPX file fixture — part of @fixme scenario
  $test.fixme();
});

When("I try to import a non-GPX file", async ({ $test }) => {
  // $test.fixme: file upload requires a real file fixture — part of @fixme scenario
  $test.fixme();
});

When(
  'I click "Download FIT" for stage {int}',
  async ({ mockedPage }, stage: number) => {
    await trackStageFitDownload(mockedPage);
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const fitButton = stageCard.getByRole("button", {
      name: /Télécharger le FIT/,
    });
    await fitButton.click();
  },
);

When(
  "stage computation is in progress",
  async ({ submitUrl, injectSequence }) => {
    // Inject route_parsed + stages_computed but NOT tripCompleteEvent (computation still running)
    await submitUrl();
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
  },
);

When(
  "I click the export button for format {string} on stage {int}",
  async ({ mockedPage }, format: string, stage: number) => {
    await trackStageExportDownload(mockedPage, format.toLowerCase());
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const exportButton = stageCard.getByRole("button", {
      name: new RegExp(format, "i"),
    });
    await exportButton.click();
  },
);

// --- Then steps FR ---

Then(
  'le bouton "Télécharger le GPX" de l\'étape {int} est actif',
  async ({ mockedPage }, stage: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const gpxButton = stageCard.getByRole("button", {
      name: /Télécharger le GPX/,
    });
    await expect(gpxButton).toBeEnabled();
  },
);

Then(
  /^une requête GET vers \/trips\/\*\/stages\/0\.gpx est envoyée$/,
  async () => {
    const gpxRequests = getTrackedStageGpxRequests();
    await expect
      .poll(() => gpxRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(gpxRequests[0]).toContain("/stages/0.gpx");
  },
);

Then(
  'le bouton "Télécharger le GPX complet" est visible et actif',
  async ({ mockedPage }) => {
    const globalGpxButton = mockedPage.getByRole("button", {
      name: /Télécharger le GPX complet/,
    });
    await expect(globalGpxButton).toBeVisible();
    await expect(globalGpxButton).toBeEnabled();
  },
);

Then(
  /^une requête GET vers \/trips\/\*\.gpx est envoyée$/,
  async () => {
    const tripGpxRequests = getTrackedTripGpxRequests();
    await expect
      .poll(() => tripGpxRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(tripGpxRequests[0]).toMatch(/\/trips\/[^/]+\.gpx$/);
  },
);

Then("le voyage est créé à partir du fichier GPX", async ({ $test }) => {
  // $test.fixme: part of @fixme scenario (GPX file upload not yet testable)
  $test.fixme();
});

Then("un message d'erreur s'affiche", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByRole("alert").or(mockedPage.getByText(/erreur/i)),
  ).toBeVisible({ timeout: 5000 });
});

Then(
  /^une requête GET vers \/trips\/\*\/stages\/0\.fit est envoyée$/,
  async () => {
    const fitRequests = getTrackedStageFitRequests();
    await expect
      .poll(() => fitRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(fitRequests[0]).toContain("/stages/0.fit");
  },
);

Then(
  "le bouton FIT de l'étape {int} est désactivé",
  async ({ mockedPage }, stage: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const fitButton = stageCard.getByRole("button", {
      name: /Télécharger le FIT/,
    });
    await expect(fitButton).toBeDisabled();
  },
);

Then(
  "le fichier téléchargé a l'extension {string}",
  async ({}, ext: string) => {
    const downloadRequests = getTrackedStageExportRequests();
    await expect
      .poll(() => downloadRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(downloadRequests[0]).toContain(`.${ext}`);
  },
);

// --- Then steps EN ---

Then(
  'the "Download GPX" button for stage {int} is enabled',
  async ({ mockedPage }, stage: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const gpxButton = stageCard.getByRole("button", {
      name: /Télécharger le GPX/,
    });
    await expect(gpxButton).toBeEnabled();
  },
);

Then(
  /^a GET request to \/trips\/\*\/stages\/0\.gpx is sent$/,
  async () => {
    const gpxRequests = getTrackedStageGpxRequests();
    await expect
      .poll(() => gpxRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(gpxRequests[0]).toContain("/stages/0.gpx");
  },
);

Then(
  'the "Télécharger le GPX complet" button is visible and enabled',
  async ({ mockedPage }) => {
    const globalGpxButton = mockedPage.getByRole("button", {
      name: /Télécharger le GPX complet/,
    });
    await expect(globalGpxButton).toBeVisible();
    await expect(globalGpxButton).toBeEnabled();
  },
);

Then(/^a GET request to \/trips\/\*\.gpx is sent$/, async () => {
  const tripGpxRequests = getTrackedTripGpxRequests();
  await expect
    .poll(() => tripGpxRequests.length, { timeout: 5000 })
    .toBeGreaterThan(0);
  expect(tripGpxRequests[0]).toMatch(/\/trips\/[^/]+\.gpx$/);
});

Then("the trip is created from the GPX file", async ({ $test }) => {
  // $test.fixme: part of @fixme scenario (GPX file upload not yet testable)
  $test.fixme();
});

Then("an error message is displayed", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByRole("alert").or(mockedPage.getByText(/error/i)),
  ).toBeVisible({ timeout: 5000 });
});

Then(
  /^a GET request to \/trips\/\*\/stages\/0\.fit is sent$/,
  async () => {
    const fitRequests = getTrackedStageFitRequests();
    await expect
      .poll(() => fitRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(fitRequests[0]).toContain("/stages/0.fit");
  },
);

Then(
  "the FIT button for stage {int} is disabled",
  async ({ mockedPage }, stage: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const fitButton = stageCard.getByRole("button", {
      name: /Télécharger le FIT/,
    });
    await expect(fitButton).toBeDisabled();
  },
);

Then(
  "the downloaded file has extension {string}",
  async ({}, ext: string) => {
    const downloadRequests = getTrackedStageExportRequests();
    await expect
      .poll(() => downloadRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(downloadRequests[0]).toContain(`.${ext}`);
  },
);
