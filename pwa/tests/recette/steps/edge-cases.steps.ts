import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Edge cases and robustness — FR + EN
// ---------------------------------------------------------------------------

// --- Given steps FR ---

Given("un voyage ne comprend qu'une seule étape", async ({ $test }) => {
  $test.fixme();
});

Given("j'ai le voyage ouvert dans deux onglets", async ({ $test }) => {
  $test.fixme();
});

// --- Given steps EN ---

Given("a trip has only one stage", async ({ $test }) => {
  $test.fixme();
});

Given("I have the trip open in two tabs", async ({ $test }) => {
  $test.fixme();
});

// --- When steps FR ---

When("je soumets {string}", async ({ submitUrl }, url: string) => {
  await submitUrl(url);
});

When(
  "l'API renvoie une erreur 500 lors de la création du voyage",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "la connexion réseau est coupée lors de la soumission du lien",
  async ({ $test }) => {
    $test.fixme();
  },
);

When("j'importe un fichier GPX vide", async ({ $test }) => {
  $test.fixme();
});

When("j'importe un fichier GPX avec un seul waypoint", async ({ $test }) => {
  $test.fixme();
});

When("je consulte ce voyage", async ({ $test }) => {
  $test.fixme();
});

When(
  "je saisis un titre de voyage de {int} caractères",
  async ({ $test }, _chars: number) => {
    $test.fixme();
  },
);

When(
  "je recharge la page pendant que le calcul des étapes est en cours",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je modifie le voyage dans l'onglet {int}",
  async ({ $test }, _tab: number) => {
    $test.fixme();
  },
);

When(
  "j'importe un fichier GPX de {int}MB",
  async ({ $test }, _size: number) => {
    $test.fixme();
  },
);

When(
  "les données météo ne sont pas disponibles pour une étape",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- When steps EN ---

When("I submit {string}", async ({ submitUrl }, url: string) => {
  await submitUrl(url);
});

When("the API returns a 500 error during trip creation", async ({ $test }) => {
  $test.fixme();
});

When(
  "the network connection is cut during link submission",
  async ({ $test }) => {
    $test.fixme();
  },
);

When("I import an empty GPX file", async ({ $test }) => {
  $test.fixme();
});

When("I import a GPX file with a single waypoint", async ({ $test }) => {
  $test.fixme();
});

When("I view that trip", async ({ $test }) => {
  $test.fixme();
});

When(
  "I enter a trip title of {int} characters",
  async ({ $test }, _chars: number) => {
    $test.fixme();
  },
);

When(
  "I reload the page while stage computation is in progress",
  async ({ $test }) => {
    $test.fixme();
  },
);

When("I modify the trip in tab {int}", async ({ $test }, _tab: number) => {
  $test.fixme();
});

When("I import a {int}MB GPX file", async ({ $test }, _size: number) => {
  $test.fixme();
});

When("weather data is not available for a stage", async ({ $test }) => {
  $test.fixme();
});

// --- Then steps FR ---

Then("un message d'erreur compréhensible est affiché", async ({ $test }) => {
  $test.fixme();
});

Then("un message d'erreur est affiché", async ({ $test }) => {
  $test.fixme();
});

Then("l'application reste utilisable", async ({ $test }) => {
  $test.fixme();
});

Then(
  "je vois un message indiquant que la source n'est pas supportée",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("un message d'erreur approprié s'affiche", async ({ $test }) => {
  $test.fixme();
});

Then(
  "un message expliquant que le fichier est insuffisant s'affiche",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("la carte de l'étape {int} s'affiche correctement", async ({ $test }) => {
  $test.fixme();
});

Then("les boutons de fusion d'étape sont désactivés", async ({ $test }) => {
  $test.fixme();
});

Then(
  "le titre est tronqué correctement dans l'interface",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("l'état du calcul est correctement récupéré", async ({ $test }) => {
  $test.fixme();
});

Then("je vois une page 404 ou un message d'erreur", async ({ $test }) => {
  $test.fixme();
});

Then(
  "l'onglet {int} reflète le changement ou affiche un avertissement",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("l'import est traité en moins de {int} secondes", async ({ $test }) => {
  $test.fixme();
});

Then("aucune erreur de mémoire ne se produit", async ({ $test }) => {
  $test.fixme();
});

Then(
  "les cartes d'étapes s'affichent correctement sans données météo",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- Then steps EN ---

Then("a comprehensible error message is displayed", async ({ $test }) => {
  $test.fixme();
});

Then("the application remains usable", async ({ $test }) => {
  $test.fixme();
});

Then(
  "I see a message indicating the source is not supported",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("an appropriate error message is displayed", async ({ $test }) => {
  $test.fixme();
});

Then(
  "a message explaining the file is insufficient is displayed",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("stage card {int} is displayed correctly", async ({ $test }) => {
  $test.fixme();
});

Then("stage merge buttons are disabled", async ({ $test }) => {
  $test.fixme();
});

Then("the title is correctly truncated in the interface", async ({ $test }) => {
  $test.fixme();
});

Then("the computation state is correctly recovered", async ({ $test }) => {
  $test.fixme();
});

Then("I see a 404 page or error message", async ({ $test }) => {
  $test.fixme();
});

Then("tab {int} reflects the change or shows a warning", async ({ $test }) => {
  $test.fixme();
});

Then("the import is processed in under {int} seconds", async ({ $test }) => {
  $test.fixme();
});

Then("no memory error occurs", async ({ $test }) => {
  $test.fixme();
});

Then(
  "stage cards are displayed correctly without weather data",
  async ({ $test }) => {
    $test.fixme();
  },
);
