import { expect, type Page } from "@playwright/test";
import { Given, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Landing page — public (unauthenticated) home page. FR + EN.
// The `mockedPage` fixture authenticates by default (auth/refresh → 200), which
// renders the TripPlanner instead of the LandingPage. These steps force a 401
// silent-refresh and re-navigate so the anonymous LandingPage is rendered.
// Testids mirror the contract pinned by tests/mocked/landing-page.spec.ts.
// ---------------------------------------------------------------------------

async function gotoLandingAsAnonymous(page: Page): Promise<void> {
  await page.route("**/auth/refresh", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({ status: 401, body: "" });
  });
  await page.goto("/");
  await page.waitForLoadState("networkidle");
  await expect(page.getByTestId("landing-page")).toBeVisible({
    timeout: 10000,
  });
}

// --- Given (FR + EN) ---

Given("je consulte la page d'atterrissage publique", async ({ mockedPage }) => {
  await gotoLandingAsAnonymous(mockedPage);
});

Given("I am viewing the public landing page", async ({ mockedPage }) => {
  await gotoLandingAsAnonymous(mockedPage);
});

Given(
  "je consulte la page d'atterrissage publique sur mobile",
  async ({ mockedPage }) => {
    await mockedPage.setViewportSize({ width: 390, height: 844 });
    await gotoLandingAsAnonymous(mockedPage);
  },
);

Given(
  "I am viewing the public landing page on mobile",
  async ({ mockedPage }) => {
    await mockedPage.setViewportSize({ width: 390, height: 844 });
    await gotoLandingAsAnonymous(mockedPage);
  },
);

// --- Then: page + sections (FR + EN) ---

Then("la page d'atterrissage est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("landing-page")).toBeVisible();
});

Then("the landing page is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("landing-page")).toBeVisible();
});

Then("la section héros est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("section-hero")).toBeVisible();
});

Then("the hero section is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("section-hero")).toBeVisible();
});

Then('la section "Comment ça marche" est visible', async ({ mockedPage }) => {
  await mockedPage.getByTestId("section-how-it-works").scrollIntoViewIfNeeded();
  await expect(mockedPage.getByTestId("section-how-it-works")).toBeVisible();
});

Then('the "how it works" section is visible', async ({ mockedPage }) => {
  await mockedPage.getByTestId("section-how-it-works").scrollIntoViewIfNeeded();
  await expect(mockedPage.getByTestId("section-how-it-works")).toBeVisible();
});

Then("la section des avantages est visible", async ({ mockedPage }) => {
  await mockedPage.getByTestId("section-features").scrollIntoViewIfNeeded();
  await expect(mockedPage.getByTestId("section-features")).toBeVisible();
});

Then("the features section is visible", async ({ mockedPage }) => {
  await mockedPage.getByTestId("section-features").scrollIntoViewIfNeeded();
  await expect(mockedPage.getByTestId("section-features")).toBeVisible();
});

Then("la grille bento est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("bento-grid")).toBeVisible();
});

Then("the bento grid is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("bento-grid")).toBeVisible();
});

Then("la section des sources est visible", async ({ mockedPage }) => {
  await mockedPage.getByTestId("section-sources").scrollIntoViewIfNeeded();
  await expect(mockedPage.getByTestId("section-sources")).toBeVisible();
});

Then("the sources section is visible", async ({ mockedPage }) => {
  await mockedPage.getByTestId("section-sources").scrollIntoViewIfNeeded();
  await expect(mockedPage.getByTestId("section-sources")).toBeVisible();
});

Then("la source {string} est affichée", async ({ mockedPage }, key: string) => {
  await expect(mockedPage.getByTestId(`source-${key}`).first()).toBeVisible();
});

Then(
  "the {string} source is displayed",
  async ({ mockedPage }, key: string) => {
    await expect(mockedPage.getByTestId(`source-${key}`).first()).toBeVisible();
  },
);

Then("la section des plateformes est visible", async ({ mockedPage }) => {
  await mockedPage.getByTestId("section-availability").scrollIntoViewIfNeeded();
  await expect(mockedPage.getByTestId("section-availability")).toBeVisible();
});

Then("the platforms section is visible", async ({ mockedPage }) => {
  await mockedPage.getByTestId("section-availability").scrollIntoViewIfNeeded();
  await expect(mockedPage.getByTestId("section-availability")).toBeVisible();
});

// --- Then: footer + CTA (FR + EN) ---

Then("le footer est visible", async ({ mockedPage }) => {
  await mockedPage.getByTestId("section-footer").scrollIntoViewIfNeeded();
  await expect(mockedPage.getByTestId("section-footer")).toBeVisible();
});

Then("the footer is visible", async ({ mockedPage }) => {
  await mockedPage.getByTestId("section-footer").scrollIntoViewIfNeeded();
  await expect(mockedPage.getByTestId("section-footer")).toBeVisible();
});

Then("le lien GitHub du footer est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("footer-github")).toBeVisible();
});

Then("the footer GitHub link is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("footer-github")).toBeVisible();
});

Then("le lien légal du footer est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("footer-legal")).toBeVisible();
});

Then("the footer legal link is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("footer-legal")).toBeVisible();
});

Then(
  "le lien de confidentialité du footer est visible",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("footer-privacy")).toBeVisible();
  },
);

Then("the footer privacy link is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("footer-privacy")).toBeVisible();
});

Then(
  "l'appel à l'action \"Créer un itinéraire\" est visible",
  async ({ mockedPage }) => {
    await expect(
      mockedPage.getByTestId("cta-create-itinerary").first(),
    ).toBeVisible();
  },
);

Then(
  'the "Créer un itinéraire" call to action is visible',
  async ({ mockedPage }) => {
    await expect(
      mockedPage.getByTestId("cta-create-itinerary").first(),
    ).toBeVisible();
  },
);

Then("le bouton de démonstration est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("cta-demo")).toBeVisible();
});

Then("the demo button is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("cta-demo")).toBeVisible();
});

Then(
  'le bouton "Créer un itinéraire" pointe vers {string}',
  async ({ mockedPage }, href: string) => {
    await expect(
      mockedPage.getByTestId("cta-create-itinerary").first(),
    ).toHaveAttribute("href", href);
  },
);

Then(
  'the "Créer un itinéraire" button points to {string}',
  async ({ mockedPage }, href: string) => {
    await expect(
      mockedPage.getByTestId("cta-create-itinerary").first(),
    ).toHaveAttribute("href", href);
  },
);
