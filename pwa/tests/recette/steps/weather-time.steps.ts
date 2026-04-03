import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Weather and travel time — FR + EN
// ---------------------------------------------------------------------------

// --- When steps FR ---

When(
  /^l'heure de départ est configurée à (\d+)h(\d+)$/,
  async ({ $test }, _hours: string, _minutes: string) => {
    $test.fixme();
  },
);

When(
  "la météo de l'étape {int} prévoit des températures sous {int}°C",
  async ({ $test }, _stage: number, _temp: number) => {
    $test.fixme();
  },
);

When(
  "la météo de l'étape {int} prévoit plus de {int}mm de pluie",
  async ({ $test }, _stage: number, _mm: number) => {
    $test.fixme();
  },
);

When(
  /^je modifie la vitesse moyenne à (\d+) km\/h dans les paramètres$/,
  async ({ $test }, _speed: number) => {
    $test.fixme();
  },
);

When(
  "le facteur de fatigue est activé",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "le mode e-bike est activé",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- When steps EN ---

When(
  /^the departure time is set to (\d+):(\d+) AM$/,
  async ({ $test }, _hours: string, _minutes: string) => {
    $test.fixme();
  },
);

When(
  "stage {int} weather forecasts temperatures below {int}°C",
  async ({ $test }, _stage: number, _temp: number) => {
    $test.fixme();
  },
);

When(
  "stage {int} weather forecasts more than {int}mm of rain",
  async ({ $test }, _stage: number, _mm: number) => {
    $test.fixme();
  },
);

When(
  /^I change the average speed to (\d+) km\/h in settings$/,
  async ({ $test }, _speed: number) => {
    $test.fixme();
  },
);

When(
  "the fatigue factor is enabled",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "e-bike mode is enabled",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- Then steps FR ---

Then(
  "la carte de l'étape {int} affiche les conditions météo",
  async () => {},
);

Then(
  "je vois la plage de températures {string} sur l'étape {int}",
  async () => {},
);

Then(
  "chaque carte d'étape affiche un temps de trajet estimé",
  async () => {},
);

Then(
  "je vois l'heure d'arrivée prévue sur chaque étape",
  async () => {},
);

Then(
  "je vois une alerte de froid sur l'étape {int}",
  async () => {},
);

Then(
  "je vois une alerte pluie sur l'étape {int}",
  async () => {},
);

Then(
  "chaque étape affiche une icône météo correspondant aux conditions",
  async () => {},
);

Then(
  "les temps de trajet de toutes les étapes sont mis à jour",
  async () => {},
);

Then(
  "la distance cible des étapes diminue progressivement",
  async () => {},
);

Then(
  "les temps de trajet sont recalculés avec une vitesse supérieure",
  async () => {},
);

// --- Then steps EN ---

Then(
  "stage card {int} shows weather conditions",
  async () => {},
);

Then(
  "I see the temperature range {string} on stage {int}",
  async () => {},
);

Then(
  "each stage card shows an estimated travel time",
  async () => {},
);

Then(
  "I see the estimated arrival time on each stage",
  async () => {},
);

Then(
  "I see a cold weather alert on stage {int}",
  async () => {},
);

Then(
  "I see a rain alert on stage {int}",
  async () => {},
);

Then(
  "each stage shows a weather icon matching its conditions",
  async () => {},
);

Then(
  "the travel times of all stages are updated",
  async () => {},
);

Then(
  "the target distance decreases progressively across stages",
  async () => {},
);

Then(
  "travel times are recalculated with a higher speed",
  async () => {},
);
