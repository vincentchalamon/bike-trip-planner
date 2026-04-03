import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Cross-cutting UX — FR + EN
// ---------------------------------------------------------------------------

// --- Given steps FR ---

Given(
  "j'ai effectué une modification d'étape",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "j'ai annulé une modification",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "l'interface est en anglais",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "le thème sombre est activé",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "je suis un nouvel utilisateur",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "le guide de démarrage est visible",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "la liste d'étapes dépasse la hauteur de l'écran",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- Given steps EN ---

Given(
  "I have made a stage modification",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "I have undone a modification",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "the interface is in English",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "dark theme is enabled",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "I am a new user",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "the getting started guide is visible",
  async ({ $test }) => {
    $test.fixme();
  },
);

Given(
  "the stage list exceeds the screen height",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- When steps FR ---

When(
  "j'appuie sur Ctrl+Z",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "j'appuie sur Ctrl+Y",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je change la langue vers {string}",
  async ({ $test }, _lang: string) => {
    $test.fixme();
  },
);

When(
  "je bascule vers le thème sombre",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je bascule vers le thème clair",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je le ferme",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je navigue avec la touche Tab dans le formulaire",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "j'effectue une action qui génère une notification",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "l'API backend est indisponible",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "je fais défiler vers le bas",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- When steps EN ---

When(
  "I press Ctrl+Z",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I press Ctrl+Y",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I change the language to {string}",
  async ({ $test }, _lang: string) => {
    $test.fixme();
  },
);

When(
  "I toggle to dark theme",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I toggle to light theme",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I close it",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I navigate with Tab key in the form",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I perform an action that generates a notification",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "the backend API is unavailable",
  async ({ $test }) => {
    $test.fixme();
  },
);

When(
  "I scroll down",
  async ({ $test }) => {
    $test.fixme();
  },
);

// --- Then steps FR ---

Then(
  "la modification est annulée",
  async () => {},
);

Then(
  "la modification est rétablie",
  async () => {},
);

Then(
  "l'interface s'affiche en anglais",
  async () => {},
);

Then(
  "l'interface s'affiche en français",
  async () => {},
);

Then(
  "l'interface s'affiche avec un fond sombre",
  async () => {},
);

Then(
  "l'interface s'affiche avec un fond clair",
  async () => {},
);

Then(
  "je vois le guide de démarrage",
  async () => {},
);

Then(
  "il n'est plus visible",
  async () => {},
);

Then(
  "le focus se déplace correctement entre les champs",
  async () => {},
);

Then(
  "un toast de confirmation s'affiche brièvement",
  async () => {},
);

Then(
  "un message d'erreur compréhensible est affiché à l'utilisateur",
  async () => {},
);

Then(
  "un bouton \"Retour en haut\" apparaît",
  async () => {},
);

// --- Then steps EN ---

Then(
  "the modification is undone",
  async () => {},
);

Then(
  "the modification is redone",
  async () => {},
);

Then(
  "the interface is displayed in English",
  async () => {},
);

Then(
  "the interface is displayed in French",
  async () => {},
);

Then(
  "the interface is displayed with a dark background",
  async () => {},
);

Then(
  "the interface is displayed with a light background",
  async () => {},
);

Then(
  "I see the getting started guide",
  async () => {},
);

Then(
  "it is no longer visible",
  async () => {},
);

Then(
  "focus moves correctly between fields",
  async () => {},
);

Then(
  "a confirmation toast briefly appears",
  async () => {},
);

Then(
  "a comprehensible error message is displayed to the user",
  async () => {},
);

Then(
  "a scroll-to-top button appears",
  async () => {},
);
