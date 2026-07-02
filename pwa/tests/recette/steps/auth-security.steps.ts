import type { Page, Response as PwResponse } from "@playwright/test";
import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import { FAKE_JWT_TOKEN, mockAllApis } from "../../fixtures/api-mocks";
import { expandLinkCard } from "../../fixtures/base.fixture";

// The navigation response of the last "navigate directly to …" step, so the
// follow-up Then can inspect its HTTP redirect chain (ADR-047 server-side gate).
const lastDirectNavigation = new WeakMap<Page, PwResponse | null>();

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
  // Magic-link `sent` state — EmailSent component shows a check-your-inbox
  // confirmation with the submitted email and a (disabled) resend button.
  await expect(page.getByTestId("magic-link-sent")).toBeVisible();
});

Then("I see the email confirmation message", async ({ page }) => {
  await expect(page.getByTestId("magic-link-sent")).toBeVisible();
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
  await page.goto("/auth/verify/token-valide");
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
  await page.goto("/auth/verify/valid-token");
});

When("je tente d'accéder à mes voyages", async ({ page }) => {
  // Protected route: unauthenticated access should redirect to /login.
  await page.goto("/trips/new");
});

When("I try to access my trips", async ({ page }) => {
  // Protected route: unauthenticated access should redirect to /login.
  await page.goto("/trips/new");
});

// --- Additional missing steps ---

async function gotoHomeWithLinkCardExpanded(
  page: import("@playwright/test").Page,
): Promise<void> {
  await page.goto("/");
  await page.waitForLoadState("networkidle");
  await expandLinkCard(page);
}

When("je navigue vers la page d'accueil", async ({ page }) => {
  await gotoHomeWithLinkCardExpanded(page);
});

When("I navigate to the home page", async ({ page }) => {
  await gotoHomeWithLinkCardExpanded(page);
});

When("je clique sur le bouton de déconnexion", async ({ $test }) => {
  $test.fixme();
});

When("I click the logout button", async ({ $test }) => {
  $test.fixme();
});

Then("je suis redirigé vers la page de connexion", async ({ page }) => {
  await expect(page).toHaveURL(/\/login/, { timeout: 5000 });
});

Then("I am redirected to the login page", async ({ page }) => {
  await expect(page).toHaveURL(/\/login/, { timeout: 5000 });
});

Then("I see the early access form", async ({ page }) => {
  await page.waitForLoadState("networkidle");
  await expect(page.getByTestId("early-access-form")).toBeVisible({
    timeout: 5000,
  });
});

Then("je vois le formulaire d'accès anticipé", async ({ page }) => {
  await page.waitForLoadState("networkidle");
  await expect(page.getByTestId("early-access-form")).toBeVisible({
    timeout: 5000,
  });
});

When("une erreur serveur se produit", async ({ mockedPage }) => {
  await mockedPage.route("**/trips", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 500,
      contentType: "application/json",
      body: JSON.stringify({ detail: "Internal Server Error" }),
    });
  });
  const input = mockedPage.getByTestId("magic-link-input");
  await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
  await input.press("Enter");
});

When("a server error occurs", async ({ mockedPage }) => {
  await mockedPage.route("**/trips", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 500,
      contentType: "application/json",
      body: JSON.stringify({ detail: "Internal Server Error" }),
    });
  });
  const input = mockedPage.getByTestId("magic-link-input");
  await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
  await input.press("Enter");
});

Then(
  "aucune trace de pile PHP n'est affichée à l'utilisateur",
  async ({ mockedPage }) => {
    const body = await mockedPage.content();
    expect(body).not.toContain("Stack trace");
    expect(body).not.toContain("vendor/");
  },
);

Then("no PHP stack trace is shown to the user", async ({ mockedPage }) => {
  const body = await mockedPage.content();
  expect(body).not.toContain("Stack trace");
  expect(body).not.toContain("vendor/");
});

When("je charge la page d'accueil", async ({ page }) => {
  await gotoHomeWithLinkCardExpanded(page);
});

When("I load the home page", async ({ page }) => {
  await gotoHomeWithLinkCardExpanded(page);
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
  // Object-level authz denials are hidden as 404, not 403 (ADR-038): a foreign
  // trip is indistinguishable from a non-existent one.
  await page.route("**/trips/other-user-trip/detail", (route) =>
    route.fulfill({
      status: 404,
      body: JSON.stringify({ detail: "Trip not found or has expired." }),
    }),
  );
  await page.goto("/trips/other-user-trip");
});

When("I try to access user B's trip", async ({ page }) => {
  // Object-level authz denials are hidden as 404, not 403 (ADR-038): a foreign
  // trip is indistinguishable from a non-existent one.
  await page.route("**/trips/other-user-trip/detail", (route) =>
    route.fulfill({
      status: 404,
      body: JSON.stringify({ detail: "Trip not found or has expired." }),
    }),
  );
  await page.goto("/trips/other-user-trip");
});

Then(
  "j'obtiens une erreur {int} ou une page non trouvée",
  async ({ page }, _code: number) => {
    await expect
      .poll(async () => {
        const errorTextVisible = await page
          .getByText(
            /403|interdit|forbidden|introuvable|not found|erreur|error|accès|access denied|impossible de charger les voyages|unable to load trips/i,
          )
          .first()
          .isVisible()
          .catch(() => false);
        const loginVisible = await page
          .getByTestId("magic-link-input")
          .isVisible()
          .catch(() => false);
        const backLinkVisible = await page
          .getByRole("link", { name: /retour aux voyages|back to trips/i })
          .isVisible()
          .catch(() => false);
        return errorTextVisible || loginVisible || backLinkVisible;
      })
      .toBe(true);
  },
);

Then(
  "I get a {int} error or a not found page",
  async ({ page }, _code: number) => {
    await expect
      .poll(async () => {
        const errorTextVisible = await page
          .getByText(
            /403|interdit|forbidden|introuvable|not found|erreur|error|accès|access denied|impossible de charger les voyages|unable to load trips/i,
          )
          .first()
          .isVisible()
          .catch(() => false);
        const loginVisible = await page
          .getByTestId("magic-link-input")
          .isVisible()
          .catch(() => false);
        const backLinkVisible = await page
          .getByRole("link", { name: /retour aux voyages|back to trips/i })
          .isVisible()
          .catch(() => false);
        return errorTextVisible || loginVisible || backLinkVisible;
      })
      .toBe(true);
  },
);

// --- Server-side gate for stale-cookie deep-links (ADR-047) ---

async function setStaleRefreshCookie(page: Page): Promise<void> {
  // A present-but-invalid refresh_token the real backend rejects: the server
  // gate must VALIDATE it (not merely detect presence) and redirect. A missing
  // cookie fails open (client-gated), so the cookie must be present here.
  // Use `url` (not domain/path) so it actually attaches over https, matching
  // landing-page.spec.ts.
  await page.context().addCookies([
    {
      name: "refresh_token",
      value: "stale-invalid-refresh-token",
      url: process.env.PLAYWRIGHT_BASE_URL ?? "https://localhost",
    },
  ]);
}

Given("j'ai un cookie de session périmé", async ({ page }) => {
  await setStaleRefreshCookie(page);
});

Given("I have a stale session cookie", async ({ page }) => {
  await setStaleRefreshCookie(page);
});

async function navigateDirectly(page: Page, path: string): Promise<void> {
  // Raw goto (no expandLinkCard): a protected deep-link must redirect before any
  // landing/link-card chrome exists.
  lastDirectNavigation.set(page, await page.goto(path));
}

When(
  "je navigue directement vers le deep-link protégé {string}",
  async ({ page }, path: string) => {
    await navigateDirectly(page, path);
  },
);

When(
  "I navigate directly to the protected deep-link {string}",
  async ({ page }, path: string) => {
    await navigateDirectly(page, path);
  },
);

function assertServerSideLoginRedirect(page: Page): void {
  // Prove the redirect was server-side (RSC gate, ADR-047), not the client
  // AuthGuard: the navigation must have followed an HTTP 3xx away from the
  // protected route. A client-side router.replace produces no such chain.
  const chain: string[] = [];
  let req = lastDirectNavigation.get(page)?.request().redirectedFrom() ?? null;
  while (req) {
    chain.push(req.url());
    req = req.redirectedFrom();
  }
  expect(chain.some((url) => url.includes("/trips/"))).toBe(true);
}

Then(
  /^je suis redirigé vers \/login par une redirection côté serveur$/,
  async ({ page }) => {
    await expect(page).toHaveURL(/\/login/, { timeout: 5000 });
    assertServerSideLoginRedirect(page);
  },
);

Then(
  /^I am redirected to \/login by a server-side redirect$/,
  async ({ page }) => {
    await expect(page).toHaveURL(/\/login/, { timeout: 5000 });
    assertServerSideLoginRedirect(page);
  },
);
