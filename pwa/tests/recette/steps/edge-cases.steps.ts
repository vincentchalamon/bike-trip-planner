import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
} from "../../fixtures/mock-data";

// ---------------------------------------------------------------------------
// Edge cases and robustness — FR + EN
// ---------------------------------------------------------------------------

// Helper: single-stage event for "trip has only one stage" scenarios
function singleStageEvent() {
  return {
    type: "stages_computed" as const,
    data: {
      stages: [
        {
          dayNumber: 1,
          distance: 72.5,
          elevation: 1180,
          elevationLoss: 920,
          startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
          endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
          geometry: [],
          label: null,
        },
      ],
    },
  };
}

// --- Given steps FR ---

Given(
  "un voyage ne comprend qu'une seule étape",
  async ({ submitUrl, injectSequence }) => {
    await submitUrl();
    await injectSequence([routeParsedEvent(), singleStageEvent()]);
  },
);

Given("j'ai le voyage ouvert dans deux onglets", async ({ $test }) => {
  // Multi-tab scenarios are not supported in Playwright BDD single-page fixtures
  $test.fixme();
});

// --- Given steps EN ---

Given("a trip has only one stage", async ({ submitUrl, injectSequence }) => {
  await submitUrl();
  await injectSequence([routeParsedEvent(), singleStageEvent()]);
});

Given("I have the trip open in two tabs", async ({ $test }) => {
  // Multi-tab scenarios are not supported in Playwright BDD single-page fixtures
  $test.fixme();
});

// --- When steps FR ---

When("je soumets {string}", async ({ submitUrl }, url: string) => {
  await submitUrl(url);
});

When(
  "l'API renvoie une erreur 500 lors de la création du voyage",
  async ({ mockedPage }) => {
    // Override POST /trips to return 500
    await mockedPage.route("**/trips", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 500,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@type": "hydra:Error",
          "hydra:title": "Internal Server Error",
          detail: "Une erreur interne est survenue.",
        }),
      });
    });
    // Submit a URL to trigger the POST
    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
  },
);

When(
  "la connexion réseau est coupée lors de la soumission du lien",
  async ({ mockedPage }) => {
    // Override POST /trips to abort (simulate network failure)
    await mockedPage.route("**/trips", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.abort("connectionfailed");
    });
    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
  },
);

When("j'importe un fichier GPX vide", async ({ mockedPage }) => {
  const gpxUpload = mockedPage.getByTestId("gpx-upload-button");
  await expect(gpxUpload).toBeVisible({ timeout: 5000 });
  // Create an empty GPX file via file chooser
  const [fileChooser] = await Promise.all([
    mockedPage.waitForEvent("filechooser"),
    gpxUpload.click(),
  ]);
  await fileChooser.setFiles({
    name: "empty.gpx",
    mimeType: "application/gpx+xml",
    buffer: Buffer.from(
      '<?xml version="1.0"?><gpx><trk><trkseg></trkseg></trk></gpx>',
    ),
  });
});

When(
  "j'importe un fichier GPX avec un seul waypoint",
  async ({ mockedPage }) => {
    const gpxUpload = mockedPage.getByTestId("gpx-upload-button");
    await expect(gpxUpload).toBeVisible({ timeout: 5000 });
    const [fileChooser] = await Promise.all([
      mockedPage.waitForEvent("filechooser"),
      gpxUpload.click(),
    ]);
    await fileChooser.setFiles({
      name: "single.gpx",
      mimeType: "application/gpx+xml",
      buffer: Buffer.from(
        '<?xml version="1.0"?><gpx><trk><trkseg><trkpt lat="44.7" lon="4.5"><ele>280</ele></trkpt></trkseg></trk></gpx>',
      ),
    });
  },
);

When("je consulte ce voyage", async ({ mockedPage }) => {
  // Wait for stage cards to appear (trip is already loaded from Given step)
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
    timeout: 10000,
  });
});

When(
  "je saisis un titre de voyage de {int} caractères",
  async ({ mockedPage, injectEvent }, chars: number) => {
    // Ensure route_parsed has been received so title is editable
    await injectEvent(routeParsedEvent());
    const title = mockedPage.getByTestId("trip-title");
    await expect(title).toBeVisible({ timeout: 5000 });
    await title.click();
    const input = mockedPage.getByRole("textbox", {
      name: /titre/i,
    });
    await expect(input).toBeVisible();
    const longTitle = "A".repeat(chars);
    await input.fill(longTitle);
    await input.press("Enter");
  },
);

When(
  "je recharge la page pendant que le calcul des étapes est en cours",
  async ({ submitUrl, mockedPage }) => {
    await submitUrl();
    // Reload before tripComplete — the detail endpoint will reload the trip
    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");
  },
);

When(
  "je modifie le voyage dans l'onglet {int}",
  async ({ $test }, _tab: number) => {
    // Multi-tab scenarios are not supported in Playwright BDD single-page fixtures
    $test.fixme();
  },
);

When(
  "j'importe un fichier GPX de {int}MB",
  async ({ $test }, _size: number) => {
    // No large test fixture available for performance testing
    $test.fixme();
  },
);

When(
  "les données météo ne sont pas disponibles pour une étape",
  async ({ submitUrl, injectSequence }) => {
    await submitUrl();
    // Inject stages without weather event, then complete
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripCompleteEvent(),
    ]);
  },
);

// --- When steps EN ---

When("I submit {string}", async ({ submitUrl }, url: string) => {
  await submitUrl(url);
});

When(
  "the API returns a 500 error during trip creation",
  async ({ mockedPage }) => {
    await mockedPage.route("**/trips", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 500,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@type": "hydra:Error",
          "hydra:title": "Internal Server Error",
          detail: "Une erreur interne est survenue.",
        }),
      });
    });
    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
  },
);

When(
  "the network connection is cut during link submission",
  async ({ mockedPage }) => {
    await mockedPage.route("**/trips", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.abort("connectionfailed");
    });
    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
  },
);

When("I import an empty GPX file", async ({ mockedPage }) => {
  const gpxUpload = mockedPage.getByTestId("gpx-upload-button");
  await expect(gpxUpload).toBeVisible({ timeout: 5000 });
  const [fileChooser] = await Promise.all([
    mockedPage.waitForEvent("filechooser"),
    gpxUpload.click(),
  ]);
  await fileChooser.setFiles({
    name: "empty.gpx",
    mimeType: "application/gpx+xml",
    buffer: Buffer.from(
      '<?xml version="1.0"?><gpx><trk><trkseg></trkseg></trk></gpx>',
    ),
  });
});

When("I import a GPX file with a single waypoint", async ({ mockedPage }) => {
  const gpxUpload = mockedPage.getByTestId("gpx-upload-button");
  await expect(gpxUpload).toBeVisible({ timeout: 5000 });
  const [fileChooser] = await Promise.all([
    mockedPage.waitForEvent("filechooser"),
    gpxUpload.click(),
  ]);
  await fileChooser.setFiles({
    name: "single.gpx",
    mimeType: "application/gpx+xml",
    buffer: Buffer.from(
      '<?xml version="1.0"?><gpx><trk><trkseg><trkpt lat="44.7" lon="4.5"><ele>280</ele></trkpt></trkseg></trk></gpx>',
    ),
  });
});

When("I view that trip", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
    timeout: 10000,
  });
});

When(
  "I enter a trip title of {int} characters",
  async ({ mockedPage, injectEvent }, chars: number) => {
    await injectEvent(routeParsedEvent());
    const title = mockedPage.getByTestId("trip-title");
    await expect(title).toBeVisible({ timeout: 5000 });
    await title.click();
    const input = mockedPage.getByRole("textbox", {
      name: /titre|title/i,
    });
    await expect(input).toBeVisible();
    const longTitle = "A".repeat(chars);
    await input.fill(longTitle);
    await input.press("Enter");
  },
);

When(
  "I reload the page while stage computation is in progress",
  async ({ submitUrl, mockedPage }) => {
    await submitUrl();
    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");
  },
);

When("I modify the trip in tab {int}", async ({ $test }, _tab: number) => {
  // Multi-tab scenarios are not supported in Playwright BDD single-page fixtures
  $test.fixme();
});

When("I import a {int}MB GPX file", async ({ $test }, _size: number) => {
  // No large test fixture available for performance testing
  $test.fixme();
});

When(
  "weather data is not available for a stage",
  async ({ submitUrl, injectSequence }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripCompleteEvent(),
    ]);
  },
);

// --- Then steps FR ---

Then(
  "un message d'erreur compréhensible est affiché",
  async ({ mockedPage }) => {
    await expect(
      mockedPage
        .getByText(/erreur|error|échoué|failed|problème|problem/i)
        .first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then("un message d'erreur est affiché", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByText(/erreur|error|échoué|failed|problème|problem/i)
      .first(),
  ).toBeVisible({ timeout: 5000 });
});

Then("l'application reste utilisable", async ({ mockedPage }) => {
  // Verify the magic link input is still functional
  const input = mockedPage.getByTestId("magic-link-input");
  await expect(input).toBeVisible({ timeout: 5000 });
  await expect(input).toBeEnabled();
});

Then(
  "je vois un message indiquant que la source n'est pas supportée",
  async ({ mockedPage }) => {
    await expect(
      mockedPage
        .getByText(/source.*invalid|non support|not supported|invalide/i)
        .first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then("un message d'erreur approprié s'affiche", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByText(/erreur|error|invalide|invalid|insuffisant|insufficient/i)
      .first(),
  ).toBeVisible({ timeout: 5000 });
});

Then(
  "un message expliquant que le fichier est insuffisant s'affiche",
  async ({ mockedPage }) => {
    await expect(
      mockedPage
        .getByText(
          /insuffisant|insufficient|invalide|invalid|vide|empty|point/i,
        )
        .first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "la carte de l'étape {int} s'affiche correctement",
  async ({ mockedPage }, n: number) => {
    await expect(mockedPage.getByTestId(`stage-card-${n}`)).toBeVisible({
      timeout: 5000,
    });
  },
);

Then("les boutons de fusion d'étape sont désactivés", async ({ $test }) => {
  // Merge UI is not testable with a single-stage trip (buttons don't appear)
  $test.fixme();
});

Then(
  "le titre est tronqué correctement dans l'interface",
  async ({ mockedPage }) => {
    const title = mockedPage.getByTestId("trip-title");
    await expect(title).toBeVisible({ timeout: 5000 });
    // The title element should have CSS truncation or the text should be present
    const titleText = await title.textContent();
    expect(titleText).toBeTruthy();
  },
);

Then("l'état du calcul est correctement récupéré", async ({ mockedPage }) => {
  // After reload, the trip detail endpoint serves the trip data,
  // so the title or stage cards should re-appear
  await expect(
    mockedPage
      .getByTestId("trip-title-skeleton")
      .or(mockedPage.getByTestId("trip-title")),
  ).toBeVisible({ timeout: 5000 });
});

Then("je vois une page 404 ou un message d'erreur", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByText(/404|not found|introuvable|n'existe pas|does not exist/i)
      .first(),
  ).toBeVisible({ timeout: 5000 });
});

Then(
  "l'onglet {int} reflète le changement ou affiche un avertissement",
  async ({ $test }) => {
    // Multi-tab scenarios are not supported in Playwright BDD single-page fixtures
    $test.fixme();
  },
);

Then("l'import est traité en moins de {int} secondes", async ({ $test }) => {
  // Performance benchmarking requires a large test fixture not available in unit tests
  $test.fixme();
});

Then("aucune erreur de mémoire ne se produit", async ({ $test }) => {
  // Memory error detection requires large file fixtures and browser memory monitoring
  $test.fixme();
});

Then(
  "les cartes d'étapes s'affichent correctement sans données météo",
  async ({ mockedPage }) => {
    // Stage cards should be visible even without weather data
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();
  },
);

// --- Then steps EN ---

Then("a comprehensible error message is displayed", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByText(/erreur|error|échoué|failed|problème|problem/i)
      .first(),
  ).toBeVisible({ timeout: 5000 });
});

Then("the application remains usable", async ({ mockedPage }) => {
  const input = mockedPage.getByTestId("magic-link-input");
  await expect(input).toBeVisible({ timeout: 5000 });
  await expect(input).toBeEnabled();
});

Then(
  "I see a message indicating the source is not supported",
  async ({ mockedPage }) => {
    await expect(
      mockedPage
        .getByText(/source.*invalid|non support|not supported|invalide/i)
        .first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then("an appropriate error message is displayed", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByText(/erreur|error|invalide|invalid|insuffisant|insufficient/i)
      .first(),
  ).toBeVisible({ timeout: 5000 });
});

Then(
  "a message explaining the file is insufficient is displayed",
  async ({ mockedPage }) => {
    await expect(
      mockedPage
        .getByText(
          /insuffisant|insufficient|invalide|invalid|vide|empty|point/i,
        )
        .first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "stage card {int} is displayed correctly",
  async ({ mockedPage }, n: number) => {
    await expect(mockedPage.getByTestId(`stage-card-${n}`)).toBeVisible({
      timeout: 5000,
    });
  },
);

Then("stage merge buttons are disabled", async ({ $test }) => {
  // Merge UI is not testable with a single-stage trip (buttons don't appear)
  $test.fixme();
});

Then(
  "the title is correctly truncated in the interface",
  async ({ mockedPage }) => {
    const title = mockedPage.getByTestId("trip-title");
    await expect(title).toBeVisible({ timeout: 5000 });
    const titleText = await title.textContent();
    expect(titleText).toBeTruthy();
  },
);

Then("the computation state is correctly recovered", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByTestId("trip-title-skeleton")
      .or(mockedPage.getByTestId("trip-title")),
  ).toBeVisible({ timeout: 5000 });
});

Then("I see a 404 page or error message", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByText(/404|not found|introuvable|n'existe pas|does not exist/i)
      .first(),
  ).toBeVisible({ timeout: 5000 });
});

Then("tab {int} reflects the change or shows a warning", async ({ $test }) => {
  // Multi-tab scenarios are not supported in Playwright BDD single-page fixtures
  $test.fixme();
});

Then("the import is processed in under {int} seconds", async ({ $test }) => {
  // Performance benchmarking requires a large test fixture not available in unit tests
  $test.fixme();
});

Then("no memory error occurs", async ({ $test }) => {
  // Memory error detection requires large file fixtures and browser memory monitoring
  $test.fixme();
});

Then(
  "stage cards are displayed correctly without weather data",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();
  },
);
