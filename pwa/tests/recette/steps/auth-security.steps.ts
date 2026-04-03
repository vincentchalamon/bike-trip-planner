import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import { FAKE_JWT_TOKEN, mockAllApis } from "../../fixtures/api-mocks";

// ---------------------------------------------------------------------------
// Auth & Security — FR + EN
// ---------------------------------------------------------------------------

Given("je suis connecté", async ({ page }) => {
  await mockAllApis(page);
  await page.goto("/");
  await page.waitForLoadState("networkidle");
});

Given("I am logged in", async ({ page }) => {
  await mockAllApis(page);
  await page.goto("/");
  await page.waitForLoadState("networkidle");
});

Given("ma session a expiré", async ({ page }) => {
  await page.route("**/auth/refresh", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({ status: 401, body: "" });
  });
});

Given("my session has expired", async ({ page }) => {
  await page.route("**/auth/refresh", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({ status: 401, body: "" });
  });
});

When(
  "je saisis {string} dans le champ email",
  async ({ page }, email: string) => {
    await page.locator('input[type="email"]').fill(email);
  },
);

When("I enter {string} in the email field", async ({ page }, email: string) => {
  await page.locator('input[type="email"]').fill(email);
});

Then("je vois un champ email", async ({ page }) => {
  await expect(page.locator('input[type="email"]')).toBeVisible();
});

Then("I see an email field", async ({ page }) => {
  await expect(page.locator('input[type="email"]')).toBeVisible();
});

// "je vois le bouton {string}" / "I see the {string} button" are defined in sharing.steps.ts

Then("je vois le message de confirmation d'envoi", async ({ page }) => {
  await expect(
    page.getByText(
      "Si votre adresse est enregistrée, vous allez recevoir un email avec un lien de connexion.",
    ),
  ).toBeVisible();
});

Then("I see the email confirmation message", async ({ page }) => {
  await expect(
    page.getByText(
      "Si votre adresse est enregistrée, vous allez recevoir un email avec un lien de connexion.",
    ),
  ).toBeVisible();
});

When(/^je navigue vers \/auth\/verify\/token-valide$/, async ({ page }) => {
  await page.route("**/auth/verify", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
    });
  });
  await page.route("**/auth/refresh", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
    });
  });
  await page.route(
    (url) => url.pathname === "/trips",
    (route, req) => {
      if (req.method() !== "GET") return route.fallback();
      return route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@context": "/contexts/Trip",
          "@id": "/trips",
          "@type": "hydra:Collection",
          "hydra:totalItems": 0,
          "hydra:member": [],
          member: [],
          totalItems: 0,
        }),
      });
    },
  );
  await page.route("**/.well-known/mercure*", (route) => route.abort());
  await page.goto("/auth/verify/test-token");
});

When(/^I navigate to \/auth\/verify\/valid-token$/, async ({ page }) => {
  await page.route("**/auth/verify", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
    });
  });
  await page.route("**/auth/refresh", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
    });
  });
  await page.route(
    (url) => url.pathname === "/trips",
    (route, req) => {
      if (req.method() !== "GET") return route.fallback();
      return route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@context": "/contexts/Trip",
          "@id": "/trips",
          "@type": "hydra:Collection",
          "hydra:totalItems": 0,
          "hydra:member": [],
          member: [],
          totalItems: 0,
        }),
      });
    },
  );
  await page.route("**/.well-known/mercure*", (route) => route.abort());
  await page.goto("/auth/verify/test-token");
});

When("je tente d'accéder à mes voyages", async ({ page }) => {
  await page.goto("/");
});

When("I try to access my trips", async ({ page }) => {
  await page.goto("/");
});

// --- Additional missing steps ---

When("je navigue vers la page d'accueil", async ({ page }) => {
  await page.goto("/");
});

When("I navigate to the home page", async ({ page }) => {
  await page.goto("/");
});

When("je clique sur le bouton de déconnexion", async ({ $test }) => {
  $test.fixme();
});

When("I click the logout button", async ({ $test }) => {
  $test.fixme();
});

Then("je suis redirigé vers la page de connexion", async ({ $test }) => {
  $test.fixme();
});

Then("I am redirected to the login page", async ({ $test }) => {
  $test.fixme();
});

When("une erreur serveur se produit", async ({ $test }) => {
  $test.fixme();
});

When("a server error occurs", async ({ $test }) => {
  $test.fixme();
});

Then(
  "aucune trace de pile PHP n'est affichée à l'utilisateur",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("no PHP stack trace is shown to the user", async ({ $test }) => {
  $test.fixme();
});

When("je charge la page d'accueil", async ({ $test }) => {
  $test.fixme();
});

When("I load the home page", async ({ $test }) => {
  $test.fixme();
});

Then(
  "les headers CSP, HSTS et X-Frame-Options sont présents",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then(
  "the CSP, HSTS and X-Frame-Options headers are present",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("toutes les ressources chargées utilisent HTTPS", async ({ $test }) => {
  $test.fixme();
});

Then("all loaded resources use HTTPS", async ({ $test }) => {
  $test.fixme();
});

Given("je suis connecté en tant qu'utilisateur A", async ({ $test }) => {
  $test.fixme();
});

Given("I am logged in as user A", async ({ $test }) => {
  $test.fixme();
});

When("je tente d'accéder au voyage de l'utilisateur B", async ({ $test }) => {
  $test.fixme();
});

When("I try to access user B's trip", async ({ $test }) => {
  $test.fixme();
});

Then(
  "j'obtiens une erreur {int} ou une page non trouvée",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("I get a {int} error or a not found page", async ({ $test }) => {
  $test.fixme();
});
