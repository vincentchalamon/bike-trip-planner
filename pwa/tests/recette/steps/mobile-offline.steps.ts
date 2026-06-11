import { expect } from "@playwright/test";
import { When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Mobile and offline mode — FR + EN
// Note: many steps are already covered by common.steps.ts (offline banner,
// magic link disabled, etc.) — only domain-specific steps are defined here.
//
// The IndexedDB "saved trips" steps were removed in #649 along with the
// front-side offline-snapshot feature.
// ---------------------------------------------------------------------------

// --- When steps FR ---

When("{int} secondes s'écoulent", async ({ mockedPage }, n: number) => {
  await mockedPage.waitForTimeout(n * 1000);
});

When(
  "je redimensionne la fenêtre à {int}px de largeur",
  async ({ mockedPage }, width: number) => {
    await mockedPage.setViewportSize({ width, height: 844 });
  },
);

When("je fais glisser la carte avec un doigt", async ({ $test }) => {
  // Touch gesture simulation on MapLibre is not reliably testable in headless
  $test.fixme();
});

// --- When steps EN ---

When("{int} seconds pass", async ({ mockedPage }, n: number) => {
  await mockedPage.waitForTimeout(n * 1000);
});

When(
  "I resize the window to {int}px width",
  async ({ mockedPage }, width: number) => {
    await mockedPage.setViewportSize({ width, height: 844 });
  },
);

When("I drag the map with one finger", async ({ $test }) => {
  // Touch gesture simulation on MapLibre is not reliably testable in headless
  $test.fixme();
});

// --- Then steps FR ---

// i18n equivalents for offline banner text
const OFFLINE_TEXT_ALTERNATIVES: Record<string, RegExp> = {
  "Hors ligne": /Hors ligne|Offline/i,
  "Connexion rétablie": /Connexion rétablie|Connection restored/i,
};

Then("le bandeau affiche {string}", async ({ mockedPage }, text: string) => {
  const pattern = OFFLINE_TEXT_ALTERNATIVES[text] ?? text;
  await expect(mockedPage.getByTestId("offline-banner")).toContainText(
    pattern,
    { timeout: 5000 },
  );
});

Then(
  'le bandeau hors ligne a role="status" et aria-live="polite"',
  async ({ mockedPage }) => {
    const banner = mockedPage.getByTestId("offline-banner");
    await expect(banner).toBeVisible({ timeout: 5000 });
    await expect(banner).toHaveAttribute("role", "status");
    await expect(banner).toHaveAttribute("aria-live", "polite");
  },
);

Then(
  "l'interface s'adapte correctement sans défilement horizontal",
  async ({ mockedPage }) => {
    const noHScroll = await mockedPage.evaluate(
      () =>
        document.documentElement.scrollWidth <=
        document.documentElement.clientWidth,
    );
    expect(noHScroll).toBe(true);
  },
);

Then("la carte se déplace en suivant le geste", async ({ $test }) => {
  // Touch gesture result cannot be reliably asserted in headless mode
  $test.fixme();
});

// --- Then steps EN ---

Then("the banner shows {string}", async ({ mockedPage }, text: string) => {
  const pattern =
    (
      {
        "Hors ligne": /Hors ligne|Offline/i,
        "Connexion rétablie": /Connexion rétablie|Connection restored/i,
      } as Record<string, RegExp>
    )[text] ?? text;
  await expect(mockedPage.getByTestId("offline-banner")).toContainText(
    pattern,
    { timeout: 5000 },
  );
});

Then(
  'the offline banner has role="status" and aria-live="polite"',
  async ({ mockedPage }) => {
    const banner = mockedPage.getByTestId("offline-banner");
    await expect(banner).toBeVisible({ timeout: 5000 });
    await expect(banner).toHaveAttribute("role", "status");
    await expect(banner).toHaveAttribute("aria-live", "polite");
  },
);

Then(
  "the interface adapts correctly without horizontal scrolling",
  async ({ mockedPage }) => {
    const noHScroll = await mockedPage.evaluate(
      () =>
        document.documentElement.scrollWidth <=
        document.documentElement.clientWidth,
    );
    expect(noHScroll).toBe(true);
  },
);

Then("the map moves following the gesture", async ({ $test }) => {
  // Touch gesture result cannot be reliably asserted in headless mode
  $test.fixme();
});
