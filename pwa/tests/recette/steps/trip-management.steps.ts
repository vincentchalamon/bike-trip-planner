import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Trip management — FR + EN
// ---------------------------------------------------------------------------

// --- Given steps FR ---

Given(
  "je suis connecté et que j'ai {int} voyages sauvegardés",
  async ({ $test }, _count: number) => {
    $test.fixme();
  },
);

Given(
  "je suis connecté et que j'ai un voyage sauvegardé",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given("j'ai récemment consulté un voyage", async ({ $test }) => {
  $test.fixme();
});

Given(
  "un voyage a été verrouillé par un autre utilisateur",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given("je suis connecté sans voyage", async ({ $test }) => {
  $test.fixme();
});

Given("j'ai un voyage sans dates de départ ni d'arrivée", async ({ $test }) => {
  $test.fixme();
});

// --- Given steps EN ---

Given(
  "I am logged in and have {int} saved trips",
  async ({ $test }, _count: number) => {
    $test.fixme();
  },
);

Given("I am logged in and have a saved trip", async ({ $test }) => {
  $test.fixme();
});

Given("I have recently viewed a trip", async ({ $test }) => {
  $test.fixme();
});

Given("a trip has been locked by another user", async ({ $test }) => {
  $test.fixme();
});

Given("I am logged in with no trips", async ({ $test }) => {
  $test.fixme();
});

Given("I have a trip with no start or end dates", async ({ $test }) => {
  $test.fixme();
});

// --- When steps FR ---

When("je clique sur ce voyage dans la liste", async ({ $test }) => {
  $test.fixme();
});

When("je duplique ce voyage", async ({ $test }) => {
  $test.fixme();
});

When("je supprime ce voyage", async ({ $test }) => {
  $test.fixme();
});

When("j'ouvre ce voyage", async ({ $test }) => {
  $test.fixme();
});

When("la liste des voyages est en cours de chargement", async ({ $test }) => {
  $test.fixme();
});

// --- When steps EN ---

When("I click on that trip in the list", async ({ $test }) => {
  $test.fixme();
});

When("I duplicate that trip", async ({ $test }) => {
  $test.fixme();
});

When("I delete that trip", async ({ $test }) => {
  $test.fixme();
});

When("I open that trip", async ({ $test }) => {
  $test.fixme();
});

When("the trip list is loading", async ({ $test }) => {
  $test.fixme();
});

// --- Then steps FR ---

Then("je vois la liste de mes {int} voyages", async ({ $test }) => {
  $test.fixme();
});

Then("je suis redirigé vers la page détail du voyage", async ({ $test }) => {
  $test.fixme();
});

Then(
  "un nouveau voyage identique apparaît dans ma liste",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("il n'apparaît plus dans ma liste", async ({ $test }) => {
  $test.fixme();
});

Then("je vois le voyage récent dans la section {string}", async ({ $test }) => {
  $test.fixme();
});

Then("je vois un indicateur de verrouillage", async ({ $test }) => {
  $test.fixme();
});

Then("les boutons de modification sont désactivés", async ({ $test }) => {
  $test.fixme();
});

Then("je vois un état vide invitant à créer un voyage", async ({ $test }) => {
  $test.fixme();
});

Then("les étapes s'affichent correctement sans dates", async ({ $test }) => {
  $test.fixme();
});

Then("je vois un indicateur de chargement", async ({ $test }) => {
  $test.fixme();
});

// --- Then steps EN ---

Then("I see my list of {int} trips", async ({ $test }) => {
  $test.fixme();
});

Then("I am redirected to the trip detail page", async ({ $test }) => {
  $test.fixme();
});

Then("a new identical trip appears in my list", async ({ $test }) => {
  $test.fixme();
});

Then("it no longer appears in my list", async ({ $test }) => {
  $test.fixme();
});

Then("I see the recent trip in the {string} section", async ({ $test }) => {
  $test.fixme();
});

Then("I see a lock indicator", async ({ $test }) => {
  $test.fixme();
});

Then("edit buttons are disabled", async ({ $test }) => {
  $test.fixme();
});

Then(
  "I see an empty state prompting me to create a trip",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("stages are displayed correctly without dates", async ({ $test }) => {
  $test.fixme();
});

Then("I see a loading indicator", async ({ $test }) => {
  $test.fixme();
});
