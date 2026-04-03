import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Stage management — FR + EN
// ---------------------------------------------------------------------------

// --- Given steps FR ---

Given(
  "un jour de repos existe après l'étape {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

// --- Given steps EN ---

Given(
  "a rest day exists after stage {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

// --- When steps FR ---

When("je clique sur le titre du voyage", async ({ $test }) => {
  $test.fixme();
});

When("je saisis {string}", async ({ $test }, _text: string) => {
  $test.fixme();
});

When(
  "je fusionne l'étape {int} avec l'étape {int}",
  async ({ $test }, _stage1: number, _stage2: number) => {
    $test.fixme();
  },
);

When(
  "je divise l'étape {int} à mi-parcours",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "je déplace le point de fin de l'étape {int} sur la carte",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "j'ajoute un jour de repos après l'étape {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When("je supprime le jour de repos", async ({ $test }) => {
  $test.fixme();
});

When("je modifie une étape", async ({ $test }) => {
  $test.fixme();
});

When("j'annule l'action avec Ctrl+Z", async ({ $test }) => {
  $test.fixme();
});

When("je rétablis l'action avec Ctrl+Y", async ({ $test }) => {
  $test.fixme();
});

// --- When steps EN ---

When("I click on the trip title", async ({ $test }) => {
  $test.fixme();
});

When("I type {string}", async ({ $test }, _text: string) => {
  $test.fixme();
});

When(
  "I merge stage {int} with stage {int}",
  async ({ $test }, _stage1: number, _stage2: number) => {
    $test.fixme();
  },
);

When("I split stage {int} at mid-route", async ({ $test }, _stage: number) => {
  $test.fixme();
});

When(
  "I drag the end point of stage {int} on the map",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "I add a rest day after stage {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When("I remove the rest day", async ({ $test }) => {
  $test.fixme();
});

When("I modify a stage", async ({ $test }) => {
  $test.fixme();
});

When("I undo with Ctrl+Z", async ({ $test }) => {
  $test.fixme();
});

When("I redo with Ctrl+Y", async ({ $test }) => {
  $test.fixme();
});

// --- Then steps FR ---

Then(
  "la carte de l'étape {int} affiche le niveau de difficulté",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("le titre affiché est {string}", async ({ $test }) => {
  $test.fixme();
});

Then("le titre n'a pas été modifié", async ({ $test }) => {
  $test.fixme();
});

Then("je ne vois plus que {int} cartes d'étapes", async ({ $test }) => {
  $test.fixme();
});

Then("je vois {int} cartes d'étapes", async ({ $test }) => {
  $test.fixme();
});

Then("la distance de l'étape {int} est recalculée", async ({ $test }) => {
  $test.fixme();
});

Then(
  "je vois un indicateur de jour de repos entre l'étape {int} et l'étape {int}",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then(
  "il n'y a plus d'indicateur de jour de repos entre l'étape {int} et l'étape {int}",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("je vois la durée totale du voyage en jours", async ({ $test }) => {
  $test.fixme();
});

Then(
  "les badges de difficulté de toutes les étapes sont cohérents avec leurs valeurs",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("l'étape est revenue à son état précédent", async ({ $test }) => {
  $test.fixme();
});

Then("l'étape est à nouveau modifiée", async ({ $test }) => {
  $test.fixme();
});

Then(
  "je vois une barre de progression pendant le calcul des étapes",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- Then steps EN ---

Then("stage card {int} shows a difficulty badge", async ({ $test }) => {
  $test.fixme();
});

Then("the displayed title is {string}", async ({ $test }) => {
  $test.fixme();
});

Then("the title has not changed", async ({ $test }) => {
  $test.fixme();
});

Then("I only see {int} stage cards", async ({ $test }) => {
  $test.fixme();
});

Then("I see {int} stage cards", async ({ $test }) => {
  $test.fixme();
});

Then("the distance of stage {int} is recalculated", async ({ $test }) => {
  $test.fixme();
});

Then(
  "I see a rest day indicator between stage {int} and stage {int}",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then(
  "there is no longer a rest day indicator between stage {int} and stage {int}",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("I see the total trip duration in days", async ({ $test }) => {
  $test.fixme();
});

Then(
  "all stage difficulty badges are consistent with their values",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("the stage has reverted to its previous state", async ({ $test }) => {
  $test.fixme();
});

Then("the stage is modified again", async ({ $test }) => {
  $test.fixme();
});

Then(
  "I see a progress bar while stages are being computed",
  async ({ $test }) => {
    $test.fixme();
  },
);
