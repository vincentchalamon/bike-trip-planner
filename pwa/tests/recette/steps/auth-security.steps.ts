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

When("je clique sur le bouton de déconnexion", async ({ page }) => {
  await page.getByRole("button", { name: /déconnex|logout/i }).click();
});

When("I click the logout button", async ({ page }) => {
  await page.getByRole("button", { name: /déconnex|logout/i }).click();
});

Then("je suis redirigé vers la page de connexion", async ({ page }) => {
  await expect(page).toHaveURL(/\/login/, { timeout: 5000 });
});

Then("I am redirected to the login page", async ({ page }) => {
  await expect(page).toHaveURL(/\/login/, { timeout: 5000 });
});

When("une erreur serveur se produit", async ({ page }) => {
  await page.route("**/trips", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 500,
      contentType: "application/json",
      body: JSON.stringify({ detail: "Internal Server Error" }),
    });
  });
  const input = page.getByTestId("magic-link-input");
  if (await input.isVisible().catch(() => false)) {
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
  }
});

When("a server error occurs", async ({ page }) => {
  await page.route("**/trips", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 500,
      contentType: "application/json",
      body: JSON.stringify({ detail: "Internal Server Error" }),
    });
  });
  const input = page.getByTestId("magic-link-input");
  if (await input.isVisible().catch(() => false)) {
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
  }
});

Then(
  "aucune trace de pile PHP n'est affichée à l'utilisateur",
  async ({ page }) => {
    const body = await page.content();
    expect(body).not.toContain("Stack trace");
    expect(body).not.toContain("vendor/");
    expect(body).not.toContain("Exception");
  },
);

Then("no PHP stack trace is shown to the user", async ({ page }) => {
  const body = await page.content();
  expect(body).not.toContain("Stack trace");
  expect(body).not.toContain("vendor/");
  expect(body).not.toContain("Exception");
});

When("je charge la page d'accueil", async ({ page }) => {
  await page.goto("/");
  await page.waitForLoadState("networkidle");
});

When("I load the home page", async ({ page }) => {
  await page.goto("/");
  await page.waitForLoadState("networkidle");
});

Then(
  "les headers CSP, HSTS et X-Frame-Options sont présents",
  async ({ $test }) => {
    // Security headers are set by the production Caddy server, not the dev/test server
    $test.fixme();
  },
);

Then(
  "the CSP, HSTS and X-Frame-Options headers are present",
  async ({ $test }) => {
    // Security headers are set by the production Caddy server, not the dev/test server
    $test.fixme();
  },
);

Then("toutes les ressources chargées utilisent HTTPS", async ({ $test }) => {
  // Dev/test server uses HTTP; HTTPS enforcement is a production Caddy concern
  $test.fixme();
});

Then("all loaded resources use HTTPS", async ({ $test }) => {
  // Dev/test server uses HTTP; HTTPS enforcement is a production Caddy concern
  $test.fixme();
});

Given("je suis connecté en tant qu'utilisateur A", async ({ page }) => {
  await mockAllApis(page);
  await page.goto("/");
  await page.waitForLoadState("networkidle");
});

Given("I am logged in as user A", async ({ page }) => {
  await mockAllApis(page);
  await page.goto("/");
  await page.waitForLoadState("networkidle");
});

When("je tente d'accéder au voyage de l'utilisateur B", async ({ page }) => {
  await page.route("**/trips/other-user-trip/detail", (route) =>
    route.fulfill({
      status: 403,
      body: JSON.stringify({ detail: "Access Denied" }),
    }),
  );
  await page.goto("/trips/other-user-trip");
});

When("I try to access user B's trip", async ({ page }) => {
  await page.route("**/trips/other-user-trip/detail", (route) =>
    route.fulfill({
      status: 403,
      body: JSON.stringify({ detail: "Access Denied" }),
    }),
  );
  await page.goto("/trips/other-user-trip");
});

Then(
  "j'obtiens une erreur {int} ou une page non trouvée",
  async ({ page }, _code: number) => {
    await expect(
      page.getByText(/403|interdit|forbidden|introuvable|not found/i).first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "I get a {int} error or a not found page",
  async ({ page }, _code: number) => {
    await expect(
      page.getByText(/403|interdit|forbidden|introuvable|not found/i).first(),
    ).toBeVisible({ timeout: 5000 });
  },
);
