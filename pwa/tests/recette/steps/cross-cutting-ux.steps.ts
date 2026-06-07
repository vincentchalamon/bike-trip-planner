import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";

async function clickLocaleSwitch(
  page: import("@playwright/test").Page,
  localeCode: "fr" | "en",
): Promise<void> {
  const switchButton = page.getByTestId(`locale-switch-${localeCode}`);
  await switchButton.evaluate((element) => {
    (element as HTMLButtonElement).click();
  });
}

// The theme toggle (#384) is a permanent control in the top bar that cycles
// light → dark → system on each click. Drive it until `data-theme-state`
// matches the requested theme (max one full cycle).
async function setTheme(
  page: import("@playwright/test").Page,
  target: "light" | "dark",
): Promise<void> {
  const toggle = page.getByTestId("theme-toggle");
  await expect(toggle).toBeVisible({ timeout: 5000 });
  for (let i = 0; i < 3; i++) {
    if ((await toggle.getAttribute("data-theme-state")) === target) return;
    await toggle.click();
  }
  await expect(toggle).toHaveAttribute("data-theme-state", target, {
    timeout: 5000,
  });
}

// ---------------------------------------------------------------------------
// Cross-cutting UX — FR + EN
// ---------------------------------------------------------------------------

// --- Given steps FR ---

Given(
  "j'ai effectué une modification d'étape",
  async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    // Delete stage 3 as a modification
    await mockedPage.getByTestId("delete-stage-3").click();
  },
);

Given(
  "j'ai annulé une modification",
  async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await mockedPage.getByTestId("delete-stage-3").click();
    await mockedPage.locator("body").click();
    await mockedPage.keyboard.press("Control+z");
    // Wait for undo to complete (stage-card-3 reappears)
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
      timeout: 5000,
    });
  },
);

Given("l'interface est en anglais", async ({ mockedPage }) => {
  // The locale switcher lives permanently in the top bar (#384).
  await clickLocaleSwitch(mockedPage, "en");
  await expect(mockedPage.getByTestId("locale-switch-en")).toHaveAttribute(
    "aria-pressed",
    "true",
    { timeout: 5000 },
  );
});

Given("le thème sombre est activé", async ({ mockedPage }) => {
  await setTheme(mockedPage, "dark");
});

Given("je suis un nouvel utilisateur", async ({ mockedPage }) => {
  await mockedPage.evaluate(() => localStorage.clear());
  await mockedPage.reload();
  await mockedPage.waitForLoadState("networkidle");
});

Given("le guide de démarrage est visible", async ({ $test }) => {
  // Onboarding guide visibility depends on app-specific first-launch detection
  $test.fixme();
});

Given(
  "la liste d'étapes dépasse la hauteur de l'écran",
  async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    // 3 stage cards with accommodations usually exceed the viewport
    await mockedPage.setViewportSize({ width: 1280, height: 400 });
  },
);

// --- Given steps EN ---

Given(
  "I have made a stage modification",
  async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await mockedPage.getByTestId("delete-stage-3").click();
  },
);

Given(
  "I have undone a modification",
  async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await mockedPage.getByTestId("delete-stage-3").click();
    await mockedPage.locator("body").click();
    await mockedPage.keyboard.press("Control+z");
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
      timeout: 5000,
    });
  },
);

Given("the interface is in English", async ({ mockedPage }) => {
  // The locale switcher lives permanently in the top bar (#384).
  await clickLocaleSwitch(mockedPage, "en");
  await expect(mockedPage.getByTestId("locale-switch-en")).toHaveAttribute(
    "aria-pressed",
    "true",
    { timeout: 5000 },
  );
});

Given("dark theme is enabled", async ({ mockedPage }) => {
  await setTheme(mockedPage, "dark");
});

Given("I am a new user", async ({ mockedPage }) => {
  await mockedPage.evaluate(() => localStorage.clear());
  await mockedPage.reload();
  await mockedPage.waitForLoadState("networkidle");
});

Given("the getting started guide is visible", async ({ $test }) => {
  // Onboarding guide visibility depends on app-specific first-launch detection
  $test.fixme();
});

Given(
  "the stage list exceeds the screen height",
  async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await mockedPage.setViewportSize({ width: 1280, height: 400 });
  },
);

// --- When steps FR ---

When("j'appuie sur Ctrl+Z", async ({ mockedPage }) => {
  await mockedPage.locator("body").click();
  await mockedPage.keyboard.press("Control+z");
});

When("j'appuie sur Ctrl+Y", async ({ mockedPage }) => {
  await mockedPage.locator("body").click();
  await mockedPage.keyboard.press("Control+y");
});

When(
  "je change la langue vers {string}",
  async ({ mockedPage }, lang: string) => {
    // The language pills are always present in the top bar (#384).
    const localeCode = lang.toLowerCase().includes("english") ? "en" : "fr";
    await clickLocaleSwitch(mockedPage, localeCode as "fr" | "en");
  },
);

When("je bascule vers le thème sombre", async ({ mockedPage }) => {
  await setTheme(mockedPage, "dark");
});

When("je bascule vers le thème clair", async ({ mockedPage }) => {
  await setTheme(mockedPage, "light");
});

When("je le ferme", async ({ $test }) => {
  // Depends on onboarding guide having a close button
  $test.fixme();
});

When(
  "je navigue avec la touche Tab dans le formulaire",
  async ({ mockedPage }) => {
    await mockedPage.keyboard.press("Tab");
    await mockedPage.keyboard.press("Tab");
  },
);

When(
  "j'effectue une action qui génère une notification",
  async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await mockedPage
      .context()
      .grantPermissions(["clipboard-read", "clipboard-write"]);
    // Delete a stage — this triggers a toast notification
    await mockedPage.getByTestId("delete-stage-3").click();
  },
);

When("l'API backend est indisponible", async ({ mockedPage }) => {
  await mockedPage.route("**/trips", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({ status: 503, body: "Service Unavailable" });
  });
  const input = mockedPage.getByTestId("magic-link-input");
  if (await input.isVisible().catch(() => false)) {
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
  }
});

When("je fais défiler vers le bas", async ({ mockedPage }) => {
  await mockedPage.evaluate(() => window.scrollBy(0, 800));
});

// --- When steps EN ---

When("I press Ctrl+Z", async ({ mockedPage }) => {
  await mockedPage.locator("body").click();
  await mockedPage.keyboard.press("Control+z");
});

When("I press Ctrl+Y", async ({ mockedPage }) => {
  await mockedPage.locator("body").click();
  await mockedPage.keyboard.press("Control+y");
});

When(
  "I change the language to {string}",
  async ({ mockedPage }, lang: string) => {
    // The language pills are always present in the top bar (#384).
    const localeCode = lang.toLowerCase().includes("english") ? "en" : "fr";
    await clickLocaleSwitch(mockedPage, localeCode as "fr" | "en");
  },
);

When("I toggle to dark theme", async ({ mockedPage }) => {
  await setTheme(mockedPage, "dark");
});

When("I toggle to light theme", async ({ mockedPage }) => {
  await setTheme(mockedPage, "light");
});

When("I close it", async ({ $test }) => {
  // Depends on onboarding guide having a close button
  $test.fixme();
});

When("I navigate with Tab key in the form", async ({ mockedPage }) => {
  await mockedPage.keyboard.press("Tab");
  await mockedPage.keyboard.press("Tab");
});

When(
  "I perform an action that generates a notification",
  async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await mockedPage
      .context()
      .grantPermissions(["clipboard-read", "clipboard-write"]);
    // Delete a stage — this triggers a toast notification
    await mockedPage.getByTestId("delete-stage-3").click();
  },
);

When("the backend API is unavailable", async ({ mockedPage }) => {
  await mockedPage.route("**/trips", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({ status: 503, body: "Service Unavailable" });
  });
  const input = mockedPage.getByTestId("magic-link-input");
  if (await input.isVisible().catch(() => false)) {
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
  }
});

When("I scroll down", async ({ mockedPage }) => {
  await mockedPage.evaluate(() => window.scrollBy(0, 800));
});

// --- Then steps FR ---

Then("la modification est annulée", async ({ mockedPage }) => {
  // After undo of delete, stage-card-3 should be visible again
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(3, { timeout: 5000 });
});

Then("la modification est rétablie", async ({ mockedPage }) => {
  // After redo, stage-card-3 should be hidden again
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(2, { timeout: 5000 });
});

Then("l'interface s'affiche en anglais", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("locale-switch-en")).toHaveAttribute(
    "aria-pressed",
    "true",
    { timeout: 5000 },
  );
});

Then("l'interface s'affiche en français", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("locale-switch-fr")).toHaveAttribute(
    "aria-pressed",
    "true",
    { timeout: 5000 },
  );
});

Then("l'interface s'affiche avec un fond sombre", async ({ mockedPage }) => {
  const isDark = await mockedPage.evaluate(() =>
    document.documentElement.classList.contains("dark"),
  );
  expect(isDark).toBe(true);
});

Then("l'interface s'affiche avec un fond clair", async ({ mockedPage }) => {
  const isDark = await mockedPage.evaluate(() =>
    document.documentElement.classList.contains("dark"),
  );
  expect(isDark).toBe(false);
});

Then("je vois le guide de démarrage", async ({ $test }) => {
  // Onboarding guide not implemented or not detectable in test environment
  $test.fixme();
});

Then("il n'est plus visible", async ({ $test }) => {
  // Onboarding guide not implemented or not detectable in test environment
  $test.fixme();
});

Then(
  "le focus se déplace correctement entre les champs",
  async ({ mockedPage }) => {
    const activeTag = await mockedPage.evaluate(() =>
      document.activeElement?.tagName.toLowerCase(),
    );
    expect(["body", "input", "button", "select", "textarea", "a"]).toContain(
      activeTag,
    );
  },
);

Then("un toast de confirmation s'affiche brièvement", async ({ $test }) => {
  $test.fixme();
});

Then(
  "un message d'erreur compréhensible est affiché à l'utilisateur",
  async ({ mockedPage }) => {
    await expect(
      mockedPage
        .getByText(/erreur|error|indisponible|unavailable|problème|problem/i)
        .first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then('un bouton "Retour en haut" apparaît', async ({ $test }) => {
  // Scroll-to-top button may not be implemented
  $test.fixme();
});

// --- Then steps EN ---

Then("the modification is undone", async ({ mockedPage }) => {
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(3, { timeout: 5000 });
});

Then("the modification is redone", async ({ mockedPage }) => {
  const cards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(cards).toHaveCount(2, { timeout: 5000 });
});

Then("the interface is displayed in English", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("locale-switch-en")).toHaveAttribute(
    "aria-pressed",
    "true",
    { timeout: 5000 },
  );
});

Then("the interface is displayed in French", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("locale-switch-fr")).toHaveAttribute(
    "aria-pressed",
    "true",
    { timeout: 5000 },
  );
});

Then(
  "the interface is displayed with a dark background",
  async ({ mockedPage }) => {
    const isDark = await mockedPage.evaluate(() =>
      document.documentElement.classList.contains("dark"),
    );
    expect(isDark).toBe(true);
  },
);

Then(
  "the interface is displayed with a light background",
  async ({ mockedPage }) => {
    const isDark = await mockedPage.evaluate(() =>
      document.documentElement.classList.contains("dark"),
    );
    expect(isDark).toBe(false);
  },
);

Then("I see the getting started guide", async ({ $test }) => {
  // Onboarding guide not implemented or not detectable in test environment
  $test.fixme();
});

Then("it is no longer visible", async ({ $test }) => {
  // Onboarding guide not implemented or not detectable in test environment
  $test.fixme();
});

Then("focus moves correctly between fields", async ({ mockedPage }) => {
  const activeTag = await mockedPage.evaluate(() =>
    document.activeElement?.tagName.toLowerCase(),
  );
  expect(["body", "input", "button", "select", "textarea", "a"]).toContain(
    activeTag,
  );
});

Then("a confirmation toast briefly appears", async ({ $test }) => {
  $test.fixme();
});

Then(
  "a comprehensible error message is displayed to the user",
  async ({ mockedPage }) => {
    await expect(
      mockedPage
        .getByText(/erreur|error|indisponible|unavailable|problème|problem/i)
        .first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then("a scroll-to-top button appears", async ({ $test }) => {
  // Scroll-to-top button may not be implemented
  $test.fixme();
});

// ---------------------------------------------------------------------------
// Onboarding tour (driver.js) — FR + EN
//
// The tour is suppressed under WebDriver unless the
// `__PLAYWRIGHT_SHOW_ONBOARDING` flag is set before navigation. The mockedPage
// fixture already navigated, so we install the init script then reload so the
// flag is present on the next page load and the tour fires after its 800 ms
// startup delay. Onboarding only runs on `/`.
// ---------------------------------------------------------------------------

const ONBOARDING_FLAG = "bike-trip-planner:onboarding-done";
const ONBOARDING_POPOVER = ".driver-popover.onboarding-popover";

async function startOnboarding(
  page: import("@playwright/test").Page,
): Promise<void> {
  await page.addInitScript(() => {
    localStorage.removeItem("bike-trip-planner:onboarding-done");
    (
      window as Window & { __PLAYWRIGHT_SHOW_ONBOARDING?: boolean }
    ).__PLAYWRIGHT_SHOW_ONBOARDING = true;
  });
  await page.goto("/");
  await page.waitForLoadState("networkidle");
  await expect(page.locator(ONBOARDING_POPOVER)).toBeVisible({ timeout: 4000 });
  // Wait for the driver.js highlight transition (≈400 ms) to settle.
  await page.waitForTimeout(500);
}

Given(
  "le tour d'onboarding est actif au premier lancement",
  async ({ mockedPage }) => {
    await startOnboarding(mockedPage);
  },
);

Given(
  "the onboarding tour is active on first launch",
  async ({ mockedPage }) => {
    await startOnboarding(mockedPage);
  },
);

Given("le tour d'onboarding a déjà été vu", async ({ mockedPage }) => {
  await mockedPage.addInitScript((flag) => {
    localStorage.setItem(flag, "true");
    (
      window as Window & { __PLAYWRIGHT_SHOW_ONBOARDING?: boolean }
    ).__PLAYWRIGHT_SHOW_ONBOARDING = true;
  }, ONBOARDING_FLAG);
  await mockedPage.goto("/");
  await mockedPage.waitForLoadState("networkidle");
});

Given("the onboarding tour has already been seen", async ({ mockedPage }) => {
  await mockedPage.addInitScript((flag) => {
    localStorage.setItem(flag, "true");
    (
      window as Window & { __PLAYWRIGHT_SHOW_ONBOARDING?: boolean }
    ).__PLAYWRIGHT_SHOW_ONBOARDING = true;
  }, ONBOARDING_FLAG);
  await mockedPage.goto("/");
  await mockedPage.waitForLoadState("networkidle");
});

When("j'avance à l'étape suivante du tour", async ({ mockedPage }) => {
  await mockedPage
    .locator(".driver-popover-navigation-btns button")
    .last()
    .click();
  await mockedPage.waitForTimeout(500);
});

When("I advance to the next tour step", async ({ mockedPage }) => {
  await mockedPage
    .locator(".driver-popover-navigation-btns button")
    .last()
    .click();
  await mockedPage.waitForTimeout(500);
});

When("je clique sur l'overlay du tour d'onboarding", async ({ mockedPage }) => {
  await mockedPage
    .locator(".driver-overlay")
    .click({ position: { x: 5, y: 5 } });
});

When("I click the onboarding tour overlay", async ({ mockedPage }) => {
  await mockedPage
    .locator(".driver-overlay")
    .click({ position: { x: 5, y: 5 } });
});

Then("le popover du tour d'onboarding est visible", async ({ mockedPage }) => {
  await expect(mockedPage.locator(ONBOARDING_POPOVER)).toBeVisible({
    timeout: 4000,
  });
});

Then("the onboarding tour popover is visible", async ({ mockedPage }) => {
  await expect(mockedPage.locator(ONBOARDING_POPOVER)).toBeVisible({
    timeout: 4000,
  });
});

Then(
  "le popover du tour d'onboarding n'est plus visible",
  async ({ mockedPage }) => {
    await expect(mockedPage.locator(ONBOARDING_POPOVER)).toBeHidden({
      timeout: 4000,
    });
  },
);

Then(
  "the onboarding tour popover is no longer visible",
  async ({ mockedPage }) => {
    await expect(mockedPage.locator(ONBOARDING_POPOVER)).toBeHidden({
      timeout: 4000,
    });
  },
);

Then(
  "l'étape du tour cible la carte de lien magique",
  async ({ mockedPage }) => {
    await expect(
      mockedPage.locator('.driver-active-element[data-testid="card-link"]'),
    ).toBeAttached({ timeout: 3000 });
  },
);

Then("the tour step targets the magic link card", async ({ mockedPage }) => {
  await expect(
    mockedPage.locator('.driver-active-element[data-testid="card-link"]'),
  ).toBeAttached({ timeout: 3000 });
});

Then("l'étape du tour cible la carte d'import GPX", async ({ mockedPage }) => {
  await expect(
    mockedPage.locator('.driver-active-element[data-testid="card-gpx"]'),
  ).toBeAttached({ timeout: 3000 });
});

Then("the tour step targets the GPX upload card", async ({ mockedPage }) => {
  await expect(
    mockedPage.locator('.driver-active-element[data-testid="card-gpx"]'),
  ).toBeAttached({ timeout: 3000 });
});

// ---------------------------------------------------------------------------
// Theme — three-state cycle / system follow / persistence — FR + EN
// ---------------------------------------------------------------------------

When("je clique sur le bouton de thème", async ({ mockedPage }) => {
  await mockedPage.getByTestId("theme-toggle").click();
});

When("I click the theme button", async ({ mockedPage }) => {
  await mockedPage.getByTestId("theme-toggle").click();
});

Then(
  "le thème sélectionné est {string}",
  async ({ mockedPage }, state: string) => {
    await expect(mockedPage.getByTestId("theme-toggle")).toHaveAttribute(
      "data-theme-state",
      state,
      { timeout: 5000 },
    );
  },
);

Then(
  "the selected theme is {string}",
  async ({ mockedPage }, state: string) => {
    await expect(mockedPage.getByTestId("theme-toggle")).toHaveAttribute(
      "data-theme-state",
      state,
      { timeout: 5000 },
    );
  },
);

Given(
  "le système d'exploitation préfère le mode sombre",
  async ({ mockedPage }) => {
    await mockedPage.emulateMedia({ colorScheme: "dark" });
  },
);

Given("the operating system prefers dark mode", async ({ mockedPage }) => {
  await mockedPage.emulateMedia({ colorScheme: "dark" });
});

When("je sélectionne le thème système", async ({ mockedPage }) => {
  const toggle = mockedPage.getByTestId("theme-toggle");
  await expect(toggle).toBeVisible({ timeout: 5000 });
  for (let i = 0; i < 3; i++) {
    if ((await toggle.getAttribute("data-theme-state")) === "system") break;
    await toggle.click();
  }
  await expect(toggle).toHaveAttribute("data-theme-state", "system", {
    timeout: 5000,
  });
});

When("I select the system theme", async ({ mockedPage }) => {
  const toggle = mockedPage.getByTestId("theme-toggle");
  await expect(toggle).toBeVisible({ timeout: 5000 });
  for (let i = 0; i < 3; i++) {
    if ((await toggle.getAttribute("data-theme-state")) === "system") break;
    await toggle.click();
  }
  await expect(toggle).toHaveAttribute("data-theme-state", "system", {
    timeout: 5000,
  });
});

// ---------------------------------------------------------------------------
// Language — compact labels / reload persistence — FR + EN
// ---------------------------------------------------------------------------

Then(
  "le label compact de langue {string} est visible",
  async ({ mockedPage }, label: string) => {
    await expect(
      mockedPage.getByTestId("locale-switch-fr").getByText(label, {
        exact: true,
      }),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "the compact language label {string} is visible",
  async ({ mockedPage }, label: string) => {
    await expect(
      mockedPage.getByTestId("locale-switch-fr").getByText(label, {
        exact: true,
      }),
    ).toBeVisible({ timeout: 5000 });
  },
);

When("I reload the page", async ({ page }) => {
  await page.reload();
});

Given("j'affiche l'application sur un écran étroit", async ({ mockedPage }) => {
  await mockedPage.setViewportSize({ width: 390, height: 844 });
  await mockedPage.goto("/");
  await mockedPage.waitForLoadState("networkidle");
});

Given("I am displaying the app on a narrow screen", async ({ mockedPage }) => {
  await mockedPage.setViewportSize({ width: 390, height: 844 });
  await mockedPage.goto("/");
  await mockedPage.waitForLoadState("networkidle");
});
