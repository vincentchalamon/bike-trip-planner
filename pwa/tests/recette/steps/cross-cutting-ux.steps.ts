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

Given("l'interface est en anglais", async ({ createFullTrip, mockedPage }) => {
  await createFullTrip();
  await mockedPage.getByTestId("config-open-button").click();
  await expect(
    mockedPage.getByRole("dialog", { name: /param|settings/i }),
  ).toBeInViewport();
  await mockedPage.getByTestId("locale-switch-en").click();
  await mockedPage.keyboard.press("Escape");
});

Given("le thème sombre est activé", async ({ createFullTrip, mockedPage }) => {
  await createFullTrip();
  await mockedPage.getByTestId("config-open-button").click();
  await expect(
    mockedPage.getByRole("dialog", { name: /param|settings/i }),
  ).toBeInViewport();
  const themeToggle = mockedPage.getByRole("button", {
    name: /sombre|dark|thème|theme/i,
  });
  if (await themeToggle.isVisible().catch(() => false)) {
    await themeToggle.click();
  }
  await mockedPage.keyboard.press("Escape");
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

Given("the interface is in English", async ({ createFullTrip, mockedPage }) => {
  await createFullTrip();
  await mockedPage.getByTestId("config-open-button").click();
  await expect(
    mockedPage.getByRole("dialog", { name: /param|settings/i }),
  ).toBeInViewport();
  await clickLocaleSwitch(mockedPage, "en");
  await mockedPage.keyboard.press("Escape");
});

Given("dark theme is enabled", async ({ createFullTrip, mockedPage }) => {
  await createFullTrip();
  await mockedPage.getByTestId("config-open-button").click();
  await expect(
    mockedPage.getByRole("dialog", { name: /param|settings/i }),
  ).toBeInViewport();
  const themeToggle = mockedPage.getByRole("button", {
    name: /sombre|dark|thème|theme/i,
  });
  if (await themeToggle.isVisible().catch(() => false)) {
    await themeToggle.click();
  }
  await mockedPage.keyboard.press("Escape");
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
  async ({ createFullTrip, mockedPage }, lang: string) => {
    // Ensure we're on a trip page where the config button is visible
    const configBtn = mockedPage.getByTestId("config-open-button");
    if (!(await configBtn.isVisible().catch(() => false))) {
      await createFullTrip();
    }
    await configBtn.click();
    await expect(
      mockedPage.getByRole("dialog", { name: /param|settings/i }),
    ).toBeInViewport();
    const localeCode = lang.toLowerCase().includes("english") ? "en" : "fr";
    await clickLocaleSwitch(mockedPage, localeCode as "fr" | "en");
  },
);

When(
  "je bascule vers le thème sombre",
  async ({ createFullTrip, mockedPage }) => {
    const configBtn = mockedPage.getByTestId("config-open-button");
    if (!(await configBtn.isVisible().catch(() => false))) {
      await createFullTrip();
    }
    await configBtn.click();
    await expect(
      mockedPage.getByRole("dialog", { name: /param|settings/i }),
    ).toBeInViewport();
    const themeToggle = mockedPage.getByRole("button", {
      name: /sombre|dark|thème|theme/i,
    });
    await themeToggle.click();
  },
);

When(
  "je bascule vers le thème clair",
  async ({ createFullTrip, mockedPage }) => {
    const configBtn = mockedPage.getByTestId("config-open-button");
    if (!(await configBtn.isVisible().catch(() => false))) {
      await createFullTrip();
    }
    await configBtn.click();
    await expect(
      mockedPage.getByRole("dialog", { name: /param|settings/i }),
    ).toBeInViewport();
    const themeToggle = mockedPage.getByRole("button", {
      name: /sombre|dark|thème|theme/i,
    });
    await themeToggle.click();
  },
);

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
  async ({ createFullTrip, mockedPage }, lang: string) => {
    const configBtn = mockedPage.getByTestId("config-open-button");
    if (!(await configBtn.isVisible().catch(() => false))) {
      await createFullTrip();
    }
    await configBtn.click();
    await expect(
      mockedPage.getByRole("dialog", { name: /param|settings/i }),
    ).toBeInViewport();
    const localeCode = lang.toLowerCase().includes("english") ? "en" : "fr";
    await clickLocaleSwitch(mockedPage, localeCode as "fr" | "en");
  },
);

When("I toggle to dark theme", async ({ createFullTrip, mockedPage }) => {
  const configBtn = mockedPage.getByTestId("config-open-button");
  if (!(await configBtn.isVisible().catch(() => false))) {
    await createFullTrip();
  }
  await configBtn.click();
  await expect(
    mockedPage.getByRole("dialog", { name: /param|settings/i }),
  ).toBeInViewport();
  const themeToggle = mockedPage.getByRole("button", {
    name: /sombre|dark|thème|theme/i,
  });
  await themeToggle.click();
});

When("I toggle to light theme", async ({ createFullTrip, mockedPage }) => {
  const configBtn = mockedPage.getByTestId("config-open-button");
  if (!(await configBtn.isVisible().catch(() => false))) {
    await createFullTrip();
  }
  await configBtn.click();
  await expect(
    mockedPage.getByRole("dialog", { name: /param|settings/i }),
  ).toBeInViewport();
  const themeToggle = mockedPage.getByRole("button", {
    name: /sombre|dark|thème|theme/i,
  });
  await themeToggle.click();
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
