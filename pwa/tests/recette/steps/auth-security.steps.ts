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

When(
  "I enter {string} in the email field",
  async ({ page }, email: string) => {
    await page.locator('input[type="email"]').fill(email);
  },
);

When(
  "je clique sur {string}",
  async ({ page }, btnName: string) => {
    await page.getByRole("button", { name: btnName }).click();
  },
);

When(
  "I click {string}",
  async ({ page }, btnName: string) => {
    await page.getByRole("button", { name: btnName }).click();
  },
);

Then("je vois un champ email", async ({ page }) => {
  await expect(page.locator('input[type="email"]')).toBeVisible();
});

Then("I see an email field", async ({ page }) => {
  await expect(page.locator('input[type="email"]')).toBeVisible();
});

Then(
  'je vois le bouton "Recevoir un lien de connexion"',
  async ({ page }) => {
    await expect(
      page.getByRole("button", { name: "Recevoir un lien de connexion" }),
    ).toBeVisible();
  },
);

Then(
  'I see the "Recevoir un lien de connexion" button',
  async ({ page }) => {
    await expect(
      page.getByRole("button", { name: "Recevoir un lien de connexion" }),
    ).toBeVisible();
  },
);

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

When(
  "je navigue vers /auth/verify/token-valide",
  async ({ page }) => {
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
  },
);

When("I navigate to /auth/verify/valid-token", async ({ page }) => {
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
