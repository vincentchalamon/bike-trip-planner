import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  weatherFetchedEvent,
  tripCompleteEvent,
  fullTripEventSequence,
} from "../../fixtures/mock-data";
import { mockAllApis } from "../../fixtures/api-mocks";
import { trackTripGpxDownload } from "../support/export-download-tracker";

// ---------------------------------------------------------------------------
// Navigation — FR + EN
// ---------------------------------------------------------------------------

Given("je suis sur la page d'accueil", async ({ mockedPage }) => {
  await mockedPage.goto("/");
  await mockedPage.waitForLoadState("networkidle");
});

Given("I am on the home page", async ({ mockedPage }) => {
  await mockedPage.goto("/");
  await mockedPage.waitForLoadState("networkidle");
});

Given("je ne suis pas connecté", async ({ page }) => {
  await page.route("**/auth/refresh", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({ status: 401, body: "" });
  });
});

Given("I am not logged in", async ({ page }) => {
  await page.route("**/auth/refresh", (route, req) => {
    if (req.method() !== "POST") return route.fallback();
    return route.fulfill({ status: 401, body: "" });
  });
});

When("je navigue vers {string}", async ({ page }, path: string) => {
  await page.goto(path);
});

When("I navigate to {string}", async ({ page }, path: string) => {
  await page.goto(path);
});

When("je recharge la page", async ({ page }) => {
  await page.reload();
});

// ---------------------------------------------------------------------------
// Trip creation — FR + EN
// ---------------------------------------------------------------------------

Given(
  "j'ai créé un voyage complet avec 3 étapes",
  async ({ createFullTrip }) => {
    await createFullTrip();
  },
);

Given(
  "I have created a full trip with 3 stages",
  async ({ createFullTrip }) => {
    await createFullTrip();
  },
);

Given(
  "j'ai créé un voyage avec des étapes contenant des données géométriques",
  async ({ submitUrl, injectEvent }) => {
    await submitUrl();
    await injectEvent({
      type: "stages_computed",
      data: {
        stages: [
          {
            dayNumber: 1,
            distance: 72.5,
            elevation: 1180,
            elevationLoss: 920,
            startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
            endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
            geometry: [
              { lat: 44.735, lon: 4.598, ele: 280 },
              { lat: 44.62, lon: 4.46, ele: 650 },
              { lat: 44.532, lon: 4.392, ele: 540 },
            ],
            label: null,
          },
          {
            dayNumber: 2,
            distance: 63.2,
            elevation: 870,
            elevationLoss: 1050,
            startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
            endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
            geometry: [
              { lat: 44.532, lon: 4.392, ele: 540 },
              { lat: 44.38, lon: 4.2, ele: 480 },
              { lat: 44.295, lon: 4.087, ele: 360 },
            ],
            label: null,
          },
          {
            dayNumber: 3,
            distance: 51.6,
            elevation: 800,
            elevationLoss: 750,
            startPoint: { lat: 44.295, lon: 4.087, ele: 360 },
            endPoint: { lat: 44.112, lon: 3.876, ele: 410 },
            geometry: [
              { lat: 44.295, lon: 4.087, ele: 360 },
              { lat: 44.112, lon: 3.876, ele: 410 },
            ],
            label: null,
          },
        ],
      },
    });
  },
);

Given(
  "I have created a trip with stages containing geometry data",
  async ({ submitUrl, injectEvent }) => {
    await submitUrl();
    await injectEvent({
      type: "stages_computed",
      data: {
        stages: [
          {
            dayNumber: 1,
            distance: 72.5,
            elevation: 1180,
            elevationLoss: 920,
            startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
            endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
            geometry: [
              { lat: 44.735, lon: 4.598, ele: 280 },
              { lat: 44.532, lon: 4.392, ele: 540 },
            ],
            label: null,
          },
          {
            dayNumber: 2,
            distance: 63.2,
            elevation: 870,
            elevationLoss: 1050,
            startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
            endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
            geometry: [
              { lat: 44.532, lon: 4.392, ele: 540 },
              { lat: 44.295, lon: 4.087, ele: 360 },
            ],
            label: null,
          },
          {
            dayNumber: 3,
            distance: 51.6,
            elevation: 800,
            elevationLoss: 750,
            startPoint: { lat: 44.295, lon: 4.087, ele: 360 },
            endPoint: { lat: 44.112, lon: 3.876, ele: 410 },
            geometry: [
              { lat: 44.295, lon: 4.087, ele: 360 },
              { lat: 44.112, lon: 3.876, ele: 410 },
            ],
            label: null,
          },
        ],
      },
    });
  },
);

When("je soumets un lien Komoot valide", async ({ submitUrl }) => {
  await submitUrl();
});

When("I submit a valid Komoot link", async ({ submitUrl }) => {
  await submitUrl();
});

When("je soumets le lien {string}", async ({ submitUrl }, link: string) => {
  await submitUrl(link);
});

When("I submit the link {string}", async ({ submitUrl }, link: string) => {
  await submitUrl(link);
});

When("l'événement route_parsed est reçu", async ({ injectEvent }) => {
  await injectEvent(routeParsedEvent());
});

When("the route_parsed event is received", async ({ injectEvent }) => {
  await injectEvent(routeParsedEvent());
});

When(
  "les événements route_parsed et stages_computed sont reçus",
  async ({ injectSequence }) => {
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
  },
);

When(
  "the route_parsed and stages_computed events are received",
  async ({ injectSequence }) => {
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
  },
);

// ---------------------------------------------------------------------------
// Accommodations context — FR + EN
// ---------------------------------------------------------------------------

Given(
  "des hébergements ont été trouvés pour les étapes 1 et 2",
  async ({ injectSequence }) => {
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      accommodationsFoundEvent(1),
      tripCompleteEvent(),
    ]);
  },
);

Given(
  "accommodations have been found for stages 1 and 2",
  async ({ injectSequence }) => {
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      accommodationsFoundEvent(1),
      tripCompleteEvent(),
    ]);
  },
);

// ---------------------------------------------------------------------------
// Assertions — pages and visibility — FR + EN
// ---------------------------------------------------------------------------

Then("je suis redirigé vers la page du voyage", async ({ mockedPage }) => {
  await expect(mockedPage).toHaveURL(/\/trips\//);
});

Then("I am redirected to the trip page", async ({ mockedPage }) => {
  await expect(mockedPage).toHaveURL(/\/trips\//);
});

Then("je reste sur la page d'accueil", async ({ mockedPage }) => {
  await expect(mockedPage).toHaveURL("/");
});

Then("I stay on the home page", async ({ mockedPage }) => {
  await expect(mockedPage).toHaveURL("/");
});

Then(
  "je vois le titre du voyage ou son squelette de chargement",
  async ({ mockedPage }) => {
    await expect(
      mockedPage
        .getByTestId("trip-title-skeleton")
        .or(mockedPage.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then("I see the trip title or its loading skeleton", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByTestId("trip-title-skeleton")
      .or(mockedPage.getByTestId("trip-title")),
  ).toBeVisible({ timeout: 5000 });
});

Then("je vois la carte de l'étape {int}", async ({ mockedPage }, n: number) => {
  await expect(mockedPage.getByTestId(`stage-card-${n}`)).toBeVisible({
    timeout: 10000,
  });
});

Then("I see stage card {int}", async ({ mockedPage }, n: number) => {
  await expect(mockedPage.getByTestId(`stage-card-${n}`)).toBeVisible({
    timeout: 10000,
  });
});

Then(
  "la distance totale affiche {string}",
  async ({ mockedPage }, value: string) => {
    await expect(mockedPage.getByTestId("total-distance")).toContainText(value);
  },
);

Then(
  "the total distance shows {string}",
  async ({ mockedPage }, value: string) => {
    await expect(mockedPage.getByTestId("total-distance")).toContainText(value);
  },
);

Then(
  "le dénivelé total affiche {string}",
  async ({ mockedPage }, value: string) => {
    await expect(mockedPage.getByTestId("total-elevation")).toContainText(
      value,
    );
  },
);

Then(
  "the total elevation shows {string}",
  async ({ mockedPage }, value: string) => {
    await expect(mockedPage.getByTestId("total-elevation")).toContainText(
      value,
    );
  },
);

// ---------------------------------------------------------------------------
// Input interactions — FR + EN
// ---------------------------------------------------------------------------

When(
  "je saisis {string} dans le champ de lien magique",
  async ({ mockedPage }, value: string) => {
    await mockedPage.getByTestId("magic-link-input").fill(value);
  },
);

When(
  "I type {string} in the magic link field",
  async ({ mockedPage }, value: string) => {
    await mockedPage.getByTestId("magic-link-input").fill(value);
  },
);

When("j'appuie sur Entrée", async ({ mockedPage }) => {
  await mockedPage.keyboard.press("Enter");
});

When("I press Enter", async ({ mockedPage }) => {
  await mockedPage.keyboard.press("Enter");
});

When("j'appuie sur Échap", async ({ mockedPage }) => {
  await mockedPage.keyboard.press("Escape");
});

When("I press Escape", async ({ mockedPage }) => {
  await mockedPage.keyboard.press("Escape");
});

When(
  "je colle l'URL {string} dans le champ de lien magique",
  async ({ mockedPage }, url: string) => {
    const input = mockedPage.getByTestId("magic-link-input");
    await input.focus();
    await mockedPage.evaluate((clipboardUrl: string) => {
      const el = document.querySelector(
        '[data-testid="magic-link-input"]',
      ) as HTMLInputElement;
      const event = new ClipboardEvent("paste", {
        bubbles: true,
        cancelable: true,
      });
      Object.defineProperty(event, "clipboardData", {
        value: { getData: () => clipboardUrl },
      });
      el.dispatchEvent(event);
    }, url);
  },
);

When(
  "I paste {string} into the magic link field",
  async ({ mockedPage }, url: string) => {
    const input = mockedPage.getByTestId("magic-link-input");
    await input.focus();
    await mockedPage.evaluate((clipboardUrl: string) => {
      const el = document.querySelector(
        '[data-testid="magic-link-input"]',
      ) as HTMLInputElement;
      const event = new ClipboardEvent("paste", {
        bubbles: true,
        cancelable: true,
      });
      Object.defineProperty(event, "clipboardData", {
        value: { getData: () => clipboardUrl },
      });
      el.dispatchEvent(event);
    }, url);
  },
);

// ---------------------------------------------------------------------------
// Error messages — FR + EN
// ---------------------------------------------------------------------------

Then(
  "je vois le message d'erreur {string}",
  async ({ mockedPage }, msg: string) => {
    await expect(mockedPage.getByText(msg)).toBeVisible();
  },
);

Then(
  "I see the error message {string}",
  async ({ mockedPage }, msg: string) => {
    await expect(mockedPage.getByText(msg)).toBeVisible();
  },
);

Then("je ne vois plus le message d'erreur", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByText("Veuillez entrer une URL valide."),
  ).toBeHidden();
});

Then("I no longer see the error message", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByText("Veuillez entrer une URL valide."),
  ).toBeHidden();
});

// ---------------------------------------------------------------------------
// Offline mode — FR + EN
// ---------------------------------------------------------------------------

Given(
  "j'utilise l'application sur un appareil mobile",
  async ({ mockedPage }) => {
    // Handled by viewport in project config
    await mockedPage.goto("/");
    await mockedPage.waitForLoadState("networkidle");
  },
);

Given("I am using the app on a mobile device", async ({ mockedPage }) => {
  await mockedPage.goto("/");
  await mockedPage.waitForLoadState("networkidle");
});

When("la connexion internet est perdue", async ({ mockedPage }) => {
  await mockedPage.evaluate(() => window.dispatchEvent(new Event("offline")));
});

When("the internet connection is lost", async ({ mockedPage }) => {
  await mockedPage.evaluate(() => window.dispatchEvent(new Event("offline")));
});

When("la connexion est rétablie", async ({ mockedPage }) => {
  await mockedPage.evaluate(() => window.dispatchEvent(new Event("online")));
});

When("the connection is restored", async ({ mockedPage }) => {
  await mockedPage.evaluate(() => window.dispatchEvent(new Event("online")));
});

Then("le bandeau hors ligne n'est pas visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("offline-banner")).not.toBeVisible();
});

Then("the offline banner is not visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("offline-banner")).not.toBeVisible();
});

Then("le bandeau hors ligne est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("offline-banner")).toBeVisible({
    timeout: 3000,
  });
});

Then("the offline banner is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("offline-banner")).toBeVisible({
    timeout: 3000,
  });
});

Then("il contient le texte {string}", async ({ mockedPage }, text: string) => {
  await expect(mockedPage.getByTestId("offline-banner")).toContainText(text);
});

Then("it contains the text {string}", async ({ mockedPage }, text: string) => {
  await expect(mockedPage.getByTestId("offline-banner")).toContainText(text);
});

Then("le bandeau hors ligne n'est plus visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("offline-banner")).not.toBeVisible();
});

Then("the offline banner is no longer visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("offline-banner")).not.toBeVisible();
});

Then(
  "le champ de saisie du lien magique est désactivé",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("magic-link-input")).toBeDisabled();
  },
);

Then("the magic link input field is disabled", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("magic-link-input")).toBeDisabled();
});

Then("le bouton d'import GPX est désactivé", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("gpx-upload-button")).toBeDisabled();
});

Then("the GPX upload button is disabled", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("gpx-upload-button")).toBeDisabled();
});

Then("le champ de saisie est à nouveau actif", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("magic-link-input")).toBeEnabled({
    timeout: 3000,
  });
});

Then("the input field is enabled again", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("magic-link-input")).toBeEnabled({
    timeout: 3000,
  });
});

// ---------------------------------------------------------------------------
// Settings panel — FR + EN
// ---------------------------------------------------------------------------

Given(
  "je suis sur la page du voyage avec les étapes calculées",
  async ({ submitUrl, injectSequence }) => {
    await submitUrl();
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
  },
);

Given(
  "I am on the trip page with computed stages",
  async ({ submitUrl, injectSequence }) => {
    await submitUrl();
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
  },
);

When("j'ouvre le panneau de paramètres", async ({ mockedPage }) => {
  await mockedPage
    .getByRole("button", { name: "Ouvrir les paramètres" })
    .click();
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).toBeInViewport();
});

When("I open the settings panel", async ({ mockedPage }) => {
  await mockedPage
    .getByRole("button", { name: "Ouvrir les paramètres" })
    .click();
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).toBeInViewport();
});

When("je clique sur le bouton {string}", async ({ page }, name: string) => {
  await page.getByRole("button", { name }).click();
});

When("I click the {string} button", async ({ page }, name: string) => {
  await page.getByRole("button", { name }).click();
});

// Generic click — shared by all domains (FR + EN)
// Share-specific button names are resolved via testIdMap; all others fall back to role lookup.
export const SHARE_BUTTON_TESTID: Record<string, string> = {
  "Copier le lien": "share-copy-link-button",
  "Copy link": "share-copy-link-button",
  "Révoquer le lien": "share-revoke-link-button",
  "Revoke link": "share-revoke-link-button",
  "Créer un lien de partage": "share-create-link-button",
  "Create share link": "share-create-link-button",
  "Télécharger l'infographie": "share-download-png-button",
  "Download infographic": "share-download-png-button",
  "Copier le texte": "share-copy-text-button",
  "Copy text": "share-copy-text-button",
};

function getGenericButtonNamePattern(btnName: string): string | RegExp {
  const expandRadiusMatch = btnName.match(
    /^(?:Expand to|Expand search to|Élargir à|Élargir le rayon à) (\d+) km$/i,
  );
  if (expandRadiusMatch) {
    const [, radius] = expandRadiusMatch;
    return new RegExp(
      `(?:Expand(?: search)? to|Élargir(?: le rayon)? à) ${radius} km`,
      "i",
    );
  }

  return new RegExp(btnName, "i");
}

When("je clique sur {string}", async ({ page }, btnName: string) => {
  if (btnName === "Télécharger le GPX complet") {
    await trackTripGpxDownload(page);
  }
  const testId = SHARE_BUTTON_TESTID[btnName];
  if (testId) {
    await page.getByTestId(testId).click();
  } else {
    await page
      .getByRole("button", { name: getGenericButtonNamePattern(btnName) })
      .click();
  }
});

When("I click {string}", async ({ page }, btnName: string) => {
  if (btnName === "Télécharger le GPX complet") {
    await trackTripGpxDownload(page);
  }
  const testId = SHARE_BUTTON_TESTID[btnName];
  if (testId) {
    await page.getByTestId(testId).click();
  } else {
    await page
      .getByRole("button", { name: getGenericButtonNamePattern(btnName) })
      .click();
  }
});

Then("le panneau de paramètres s'affiche", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).toBeInViewport();
});

Then("the settings panel is displayed", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).toBeInViewport();
});

Then("le panneau de paramètres est fermé", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).not.toBeInViewport();
});

Then("the settings panel is closed", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).not.toBeInViewport();
});

// ---------------------------------------------------------------------------
// Share modal — FR + EN
// ---------------------------------------------------------------------------

Given(
  "j'ai créé un voyage complet avec un lien de partage actif",
  async ({ createFullTrip, mockedPage }) => {
    const { getTripId } = await import("../../fixtures/api-mocks");
    await mockedPage
      .context()
      .grantPermissions(["clipboard-read", "clipboard-write"]);
    await mockedPage.route(
      `**/trips/${getTripId()}/share`,
      (route, request) => {
        if (request.method() === "GET") {
          return route.fulfill({
            status: 200,
            contentType: "application/ld+json",
            body: JSON.stringify({
              shortCode: "Ab3kX9mP",
              token: "share-token-xyz",
              createdAt: new Date().toISOString(),
            }),
          });
        }
        if (request.method() === "DELETE") {
          return route.fulfill({ status: 204, body: "" });
        }
        if (request.method() === "POST") {
          return route.fulfill({
            status: 201,
            contentType: "application/ld+json",
            body: JSON.stringify({
              shortCode: "NewCode1",
              token: "new-token",
              createdAt: new Date().toISOString(),
            }),
          });
        }
        return route.fallback();
      },
    );
    await createFullTrip();
  },
);

Given(
  "I have created a full trip with an active share link",
  async ({ createFullTrip, mockedPage }) => {
    const { getTripId } = await import("../../fixtures/api-mocks");
    await mockedPage
      .context()
      .grantPermissions(["clipboard-read", "clipboard-write"]);
    await mockedPage.route(
      `**/trips/${getTripId()}/share`,
      (route, request) => {
        if (request.method() === "GET") {
          return route.fulfill({
            status: 200,
            contentType: "application/ld+json",
            body: JSON.stringify({
              shortCode: "Ab3kX9mP",
              token: "share-token-xyz",
              createdAt: new Date().toISOString(),
            }),
          });
        }
        if (request.method() === "DELETE") {
          return route.fulfill({ status: 204, body: "" });
        }
        if (request.method() === "POST") {
          return route.fulfill({
            status: 201,
            contentType: "application/ld+json",
            body: JSON.stringify({
              shortCode: "NewCode1",
              token: "new-token",
              createdAt: new Date().toISOString(),
            }),
          });
        }
        return route.fallback();
      },
    );
    await createFullTrip();
  },
);

When("je clique sur le bouton de partage", async ({ mockedPage }) => {
  await mockedPage.getByTestId("share-button").click();
});

When("I click the share button", async ({ mockedPage }) => {
  await mockedPage.getByTestId("share-button").click();
});

Then("la modale de partage s'affiche", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).toBeVisible({
    timeout: 5000,
  });
});

Then("the share modal is displayed", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).toBeVisible({
    timeout: 5000,
  });
});

// ---------------------------------------------------------------------------
// Map — FR + EN
// ---------------------------------------------------------------------------

Then("le panneau carte est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("map-panel")).toBeVisible({
    timeout: 5000,
  });
});

Then("the map panel is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("map-panel")).toBeVisible({
    timeout: 5000,
  });
});

Then("le panneau carte n'est pas visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("map-panel")).not.toBeVisible();
});

Then("the map panel is not visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("map-panel")).not.toBeVisible();
});

Then("le profil altimétrique est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("elevation-profile")).toBeVisible({
    timeout: 5000,
  });
});

Then("the elevation profile is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("elevation-profile")).toBeVisible({
    timeout: 5000,
  });
});

// ---------------------------------------------------------------------------
// Geocoding — FR + EN
// ---------------------------------------------------------------------------

Then(
  "l'étape {int} affiche {string} comme point de départ",
  async ({ mockedPage }, stage: number, city: string) => {
    await expect(
      mockedPage.getByTestId(`stage-${stage}-departure`),
    ).toContainText(city, { timeout: 5000 });
  },
);

Then(
  "stage {int} shows {string} as departure",
  async ({ mockedPage }, stage: number, city: string) => {
    await expect(
      mockedPage.getByTestId(`stage-${stage}-departure`),
    ).toContainText(city, { timeout: 5000 });
  },
);

Then(
  "l'étape {int} affiche {string} comme point d'arrivée",
  async ({ mockedPage }, stage: number, city: string) => {
    await expect(
      mockedPage.getByTestId(`stage-${stage}-arrival`),
    ).toContainText(city, { timeout: 5000 });
  },
);

Then(
  "stage {int} shows {string} as arrival",
  async ({ mockedPage }, stage: number, city: string) => {
    await expect(
      mockedPage.getByTestId(`stage-${stage}-arrival`),
    ).toContainText(city, { timeout: 5000 });
  },
);

// ---------------------------------------------------------------------------
// Auth — FR + EN
// ---------------------------------------------------------------------------

When("je navigue vers la page de connexion", async ({ page }) => {
  await page.goto("/login");
  await page.waitForLoadState("networkidle");
});

When("I navigate to the login page", async ({ page }) => {
  await page.goto("/login");
  await page.waitForLoadState("networkidle");
});

Then(/^je suis redirigé vers \/login$/, async ({ page }) => {
  await page.waitForURL(/\/login/, { timeout: 5000 });
  await expect(page).toHaveURL(/\/login/);
});

Then(/^I am redirected to \/login$/, async ({ page }) => {
  await page.waitForURL(/\/login/, { timeout: 5000 });
  await expect(page).toHaveURL(/\/login/);
});

Then("je suis redirigé vers la page d'accueil", async ({ page }) => {
  await page.waitForURL("/", { timeout: 5000 });
  await expect(page).toHaveURL("/");
});

Then("I am redirected to the home page", async ({ page }) => {
  await page.waitForURL("/", { timeout: 5000 });
  await expect(page).toHaveURL("/");
});
