import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Trip creation — FR + EN
// Note: many steps are already covered by common.steps.ts (navigation,
// submitUrl, route_parsed/stages_computed events, stage cards visibility,
// total distance/elevation, geocoding, error messages, paste URL, etc.)
// Only domain-specific steps not in common.steps.ts are defined here.
// ---------------------------------------------------------------------------

// --- Then steps FR ---

Then(
  "je vois un champ de saisie avec le placeholder {string}",
  async () => {},
);

Then(
  "je vois le champ de saisie du lien magique",
  async () => {},
);

// --- Then steps EN ---

Then(
  "I see an input field with placeholder {string}",
  async () => {},
);

Then(
  "I see the magic link input field",
  async () => {},
);
