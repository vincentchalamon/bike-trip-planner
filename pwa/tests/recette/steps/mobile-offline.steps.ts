import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Mobile and offline mode — FR + EN
// Note: many steps are already covered by common.steps.ts (offline banner,
// magic link disabled, etc.) — only domain-specific steps are defined here.
// ---------------------------------------------------------------------------

// --- Given steps FR ---

Given(
  "un voyage a été précédemment sauvegardé localement",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- Given steps EN ---

Given("a trip has been previously saved locally", async ({ $test }) => {
  $test.fixme();
});

// --- When steps FR ---

When("{int} secondes s'écoulent", async ({ $test }, _n: number) => {
  $test.fixme();
});

When("un voyage complet est créé", async ({ $test }) => {
  $test.fixme();
});

When(
  "je redimensionne la fenêtre à {int}px de largeur",
  async ({ $test }, _width: number) => {
    $test.fixme();
  },
);

When("je fais glisser la carte avec un doigt", async ({ $test }) => {
  $test.fixme();
});

// --- When steps EN ---

When("3 seconds pass", async ({ $test }) => {
  $test.fixme();
});

When("a full trip is created", async ({ $test }) => {
  $test.fixme();
});

When(
  "I resize the window to {int}px width",
  async ({ $test }, _width: number) => {
    $test.fixme();
  },
);

When("I drag the map with one finger", async ({ $test }) => {
  $test.fixme();
});

// --- Then steps FR ---

Then("le bandeau affiche {string}", async ({ $test }) => {
  $test.fixme();
});

Then(
  'le bandeau hors ligne a role="status" et aria-live="polite"',
  async ({ $test }) => {
    $test.fixme();
  },
);

Then(
  "le voyage est sauvegardé localement dans IndexedDB",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("je peux consulter les étapes du voyage", async ({ $test }) => {
  $test.fixme();
});

Then(
  "l'interface s'adapte correctement sans défilement horizontal",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("la carte se déplace en suivant le geste", async ({ $test }) => {
  $test.fixme();
});

// --- Then steps EN ---

Then("the banner shows {string}", async ({ $test }) => {
  $test.fixme();
});

Then(
  'the offline banner has role="status" and aria-live="polite"',
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("the trip is saved locally in IndexedDB", async ({ $test }) => {
  $test.fixme();
});

Then("I can view the trip stages", async ({ $test }) => {
  $test.fixme();
});

Then(
  "the interface adapts correctly without horizontal scrolling",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("the map moves following the gesture", async ({ $test }) => {
  $test.fixme();
});
