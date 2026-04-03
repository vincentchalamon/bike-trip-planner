import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Alerts and analysis — FR + EN
// ---------------------------------------------------------------------------

// --- When steps FR ---

When(
  "une étape dépasse la distance maximale configurée",
  async () => {},
);

When(
  "une étape a un dénivelé supérieur à 2000m",
  async () => {},
);

When(
  "les données météo indiquent de la pluie sur l'étape {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "aucun hébergement n'est trouvé dans un rayon de {int} km pour une étape",
  async ({ $test }, _radius: number) => {
    $test.fixme();
  },
);

When(
  "une longue portion de route ne contient aucun point de ravitaillement",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "les dernières étapes cumulent trop de dénivelé",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "une étape passe près d'un site touristique majeur",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "plusieurs alertes existent sur une même étape",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "l'étape a une distance de {int} km et un dénivelé de {int} m",
  async ({ $test }, _distance: number, _elevation: number) => {
    $test.fixme();
  },
);

// --- When steps EN ---

When(
  "a stage exceeds the configured maximum distance",
  async () => {},
);

When(
  "a stage has more than {int}m elevation gain",
  async () => {},
);

When(
  "weather data indicates rain on stage {int}",
  async ({ $test }, _stage: number) => {
    $test.fixme();
  },
);

When(
  "no accommodation is found within {int} km for a stage",
  async ({ $test }, _radius: number) => {
    $test.fixme();
  },
);

When(
  "a long route section has no supply points",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "the last stages accumulate too much elevation gain",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "a stage passes near a major tourist site",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "multiple alerts exist on the same stage",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "the stage has a distance of {int} km and elevation of {int} m",
  async ({ $test }, _distance: number, _elevation: number) => {
    $test.fixme();
  },
);

// --- Given steps FR ---

Given(
  "toutes les étapes sont dans des limites raisonnables",
  async () => {},
);

// --- Given steps EN ---

Given(
  "all stages are within reasonable limits",
  async () => {},
);

// --- Then steps FR ---

Then(
  "je vois une alerte de distance excessive sur cette étape",
  async () => {},
);

Then(
  "je vois une alerte de dénivelé important sur cette étape",
  async () => {},
);

Then(
  "je vois une alerte météo sur la carte de l'étape {int}",
  async () => {},
);

Then(
  "je vois une alerte d'hébergement sur cette étape",
  async () => {},
);

Then(
  "je vois une alerte de ravitaillement sur l'étape concernée",
  async () => {},
);

Then(
  "je vois une alerte de fatigue progressive sur les dernières étapes",
  async () => {},
);

Then(
  "je vois une notification de POI culturel sur cette étape",
  async () => {},
);

Then(
  "aucune alerte critique n'est affichée",
  async () => {},
);

Then(
  "elles s'affichent dans l'ordre de sévérité décroissante",
  async () => {},
);

Then(
  "le niveau de difficulté est {string}",
  async () => {},
);

// --- Then steps EN ---

Then(
  "I see an excessive distance alert on that stage",
  async () => {},
);

Then(
  "I see a high elevation alert on that stage",
  async () => {},
);

Then(
  "I see a weather alert on stage card {int}",
  async () => {},
);

Then(
  "I see an accommodation alert on that stage",
  async () => {},
);

Then(
  "I see a supply alert on the affected stage",
  async () => {},
);

Then(
  "I see a progressive fatigue alert on the last stages",
  async () => {},
);

Then(
  "I see a cultural POI notification on that stage",
  async () => {},
);

Then(
  "no critical alerts are displayed",
  async () => {},
);

Then(
  "they are displayed in descending order of severity",
  async () => {},
);

Then(
  "the difficulty level is {string}",
  async () => {},
);
