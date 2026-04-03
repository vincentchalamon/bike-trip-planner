import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Export GPX and FIT — FR + EN
// ---------------------------------------------------------------------------

// --- When steps FR ---

When(
  "je clique sur \"Télécharger le GPX\" de l'étape {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "je sélectionne un fichier GPX valide",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je tente d'importer un fichier non-GPX",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je clique sur \"Télécharger le FIT\" de l'étape {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "le calcul des étapes est en cours",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je clique sur le bouton export de format {string} de l'étape {int}",
  async ({ $test }, _format: string, _stage: number) => {
    $test.fixme();
  },
);

// --- When steps EN ---

When(
  "I click \"Download GPX\" for stage {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "I select a valid GPX file",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I try to import a non-GPX file",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I click \"Download FIT\" for stage {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "stage computation is in progress",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I click the export button for format {string} on stage {int}",
  async ({ $test }, _format: string, _stage: number) => {
    $test.fixme();
  },
);

// --- Then steps FR ---

Then(
  "le bouton \"Télécharger le GPX\" de l'étape {int} est actif",
  async () => {},
);

Then(
  /^une requête GET vers \/trips\/\*\/stages\/0\.gpx est envoyée$/,
  async () => {},
);

Then(
  "le bouton \"Télécharger le GPX complet\" est visible et actif",
  async () => {},
);

Then(
  /^une requête GET vers \/trips\/\*\.gpx est envoyée$/,
  async () => {},
);

Then(
  "le voyage est créé à partir du fichier GPX",
  async () => {},
);

Then(
  "un message d'erreur s'affiche",
  async () => {},
);

Then(
  /^une requête GET vers \/trips\/\*\/stages\/0\.fit est envoyée$/,
  async () => {},
);

Then(
  "le bouton FIT de l'étape {int} est désactivé",
  async () => {},
);

Then(
  "le fichier téléchargé a l'extension {string}",
  async () => {},
);

// --- Then steps EN ---

Then(
  "the \"Download GPX\" button for stage {int} is enabled",
  async () => {},
);

Then(
  /^a GET request to \/trips\/\*\/stages\/0\.gpx is sent$/,
  async () => {},
);

Then(
  "the \"Télécharger le GPX complet\" button is visible and enabled",
  async () => {},
);

Then(
  /^a GET request to \/trips\/\*\.gpx is sent$/,
  async () => {},
);

Then(
  "the trip is created from the GPX file",
  async () => {},
);

Then(
  "an error message is displayed",
  async () => {},
);

Then(
  /^a GET request to \/trips\/\*\/stages\/0\.fit is sent$/,
  async () => {},
);

Then(
  "the FIT button for stage {int} is disabled",
  async () => {},
);

Then(
  "the downloaded file has extension {string}",
  async () => {},
);
