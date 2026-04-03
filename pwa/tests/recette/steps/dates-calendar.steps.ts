import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Dates and calendar — FR + EN
// ---------------------------------------------------------------------------

// --- Given steps FR ---

Given(
  "le voyage n'a pas de date de départ",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- Given steps EN ---

Given(
  "the trip has no departure date",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- When steps FR ---

When(
  "j'ouvre le sélecteur de dates",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je sélectionne le {int} {word} {int} comme date de départ",
  async ({ $test }, _day: number, _month: string, _year: number) => {
    $test.fixme();
  },
);

When(
  "je sélectionne le {string} comme date de départ",
  async ({ $test }, _date: string) => {
    $test.fixme();
  },
);

When(
  "je définis le {int} {word} {int} comme date de départ",
  async ({ $test }, _day: number, _month: string, _year: number) => {
    $test.fixme();
  },
);

When(
  "je définis le {string} comme date de départ",
  async ({ $test }, _date: string) => {
    $test.fixme();
  },
);

When(
  "un jour de repos est ajouté après l'étape {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "je définis une date de départ",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je définis une date de départ dans les {int} prochains jours",
  async ({ $test }, _days: number) => {
    $test.fixme();
  },
);

When(
  "je supprime la date de départ",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "j'ouvre le calendrier",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je navigue vers le mois suivant",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- When steps EN ---

When(
  "I open the date picker",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  /^I select (\w+ \d+, \d+) as the departure date$/,
  async ({ $test }, _date: string) => {
    $test.fixme();
  },
);

When(
  /^I set (\w+ \d+, \d+) as the departure date$/,
  async ({ $test }, _date: string) => {
    $test.fixme();
  },
);

When(
  "a rest day is added after stage {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "I set a departure date",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I set a departure date within the next {int} days",
  async ({ $test }, _days: number) => {
    $test.fixme();
  },
);

When(
  "I remove the departure date",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I open the calendar",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I navigate to the next month",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- Then steps FR ---

Then(
  "la date de départ affichée est {string}",
  async () => {},
);

Then(
  /^l'étape (\d+) est prévue le \d+ \w+ \d+$/,
  async () => {},
);

Then(
  "le calendrier affiche toutes les étapes avec leurs dates",
  async () => {},
);

Then(
  "les prévisions météo sont associées aux dates des étapes",
  async () => {},
);

Then(
  "les étapes n'affichent plus de dates",
  async () => {},
);

Then(
  "les cartes d'étapes n'affichent pas de dates",
  async () => {},
);

Then(
  "le mois suivant est affiché",
  async () => {},
);

// --- Then steps EN ---

Then(
  "the displayed departure date is {string}",
  async () => {},
);

Then(
  /^stage (\d+) is scheduled for \w+ \d+, \d+$/,
  async () => {},
);

Then(
  "the calendar shows all stages with their dates",
  async () => {},
);

Then(
  "weather forecasts are associated with stage dates",
  async () => {},
);

Then(
  "stages no longer show dates",
  async () => {},
);

Then(
  "stage cards do not show dates",
  async () => {},
);

Then(
  "the next month is displayed",
  async () => {},
);
