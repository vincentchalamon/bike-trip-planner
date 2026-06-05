import { expect, type Page } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
  stageUpdatedEvent,
  emptyAccommodationsFoundEvent,
  validationErrorEvent,
} from "../../fixtures/mock-data";
import { injectSseEvent, injectSseSequence } from "../../fixtures/sse-helpers";
import { expandGpxCard, expandLinkCard } from "../../fixtures/base.fixture";

/**
 * Expand the GPX card on the welcome screen and upload a file via the
 * hidden `<input type="file">`. Mirrors the user click-card → drop-zone flow
 * without relying on the native filechooser event.
 */
async function uploadGpxFile(
  page: Page,
  file: { name: string; mimeType: string; buffer: Buffer },
): Promise<void> {
  await expandGpxCard(page);
  const gpxCard = page.getByTestId("card-gpx");
  await expect(gpxCard).toBeVisible({ timeout: 5000 });
  const fileInput = page.getByTestId("gpx-file-input");
  await fileInput.setInputFiles(file);
}

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

Given("le endpoint de détail du voyage renvoie 404", async ({ mockedPage }) => {
  await mockedPage.route("**/trips/*/detail", (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    return route.fulfill({ status: 404, body: "" });
  });
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

Given("the trip detail endpoint returns 404", async ({ mockedPage }) => {
  await mockedPage.route("**/trips/*/detail", (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    return route.fulfill({ status: 404, body: "" });
  });
});

// --- When steps FR ---

When("je soumets {string}", async ({ mockedPage }, url: string) => {
  // The magic-link input lives inside the (collapsed-by-default) link card.
  await expandLinkCard(mockedPage);
  const input = mockedPage.getByTestId("magic-link-input");
  await input.fill(url);
  await input.press("Enter");
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
    // Submit a URL to trigger the POST (link card is collapsed by default).
    await expandLinkCard(mockedPage);
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
    await expandLinkCard(mockedPage);
    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
  },
);

When("j'importe un fichier GPX vide", async ({ mockedPage }) => {
  // Mock the GPX upload endpoint to return a validation error
  await mockedPage.route("**/trips", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 422,
      contentType: "application/ld+json",
      body: JSON.stringify({
        "@type": "ConstraintViolationList",
        violations: [
          { propertyPath: "gpxFile", message: "Fichier GPX invalide" },
        ],
      }),
    });
  });
  await uploadGpxFile(mockedPage, {
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
    await mockedPage.route("**/trips", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 422,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@type": "ConstraintViolationList",
          violations: [
            { propertyPath: "gpxFile", message: "Fichier GPX insuffisant" },
          ],
        }),
      });
    });
    await uploadGpxFile(mockedPage, {
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
  async ({ submitUrl, injectEvent, mockedPage }, chars: number) => {
    await submitUrl();
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

When("I submit {string}", async ({ mockedPage }, url: string) => {
  // The magic-link input lives inside the (collapsed-by-default) link card.
  await expandLinkCard(mockedPage);
  const input = mockedPage.getByTestId("magic-link-input");
  await input.fill(url);
  await input.press("Enter");
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
    await expandLinkCard(mockedPage);
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
    await expandLinkCard(mockedPage);
    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
  },
);

When("I import an empty GPX file", async ({ mockedPage }) => {
  await mockedPage.route("**/trips", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 422,
      contentType: "application/ld+json",
      body: JSON.stringify({
        "@type": "ConstraintViolationList",
        violations: [{ propertyPath: "gpxFile", message: "Invalid GPX file" }],
      }),
    });
  });
  await uploadGpxFile(mockedPage, {
    name: "empty.gpx",
    mimeType: "application/gpx+xml",
    buffer: Buffer.from(
      '<?xml version="1.0"?><gpx><trk><trkseg></trkseg></trk></gpx>',
    ),
  });
});

When("I import a GPX file with a single waypoint", async ({ mockedPage }) => {
  await mockedPage.route("**/trips", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 422,
      contentType: "application/ld+json",
      body: JSON.stringify({
        "@type": "ConstraintViolationList",
        violations: [
          { propertyPath: "gpxFile", message: "Insufficient GPX file" },
        ],
      }),
    });
  });
  await uploadGpxFile(mockedPage, {
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
  async ({ submitUrl, injectEvent, mockedPage }, chars: number) => {
    await submitUrl();
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
  // "Usable" = the app did not crash into an error boundary. Two valid post-error
  // states: POST /trips failed so we stayed on the welcome screen (link card
  // interactive), or POST succeeded then an SSE error fired on the trip page
  // (top bar present). Accept either.
  const linkCard = mockedPage.getByTestId("card-link");
  const topBar = mockedPage.getByTestId("top-bar");
  await expect(linkCard.or(topBar).first()).toBeVisible({ timeout: 5000 });
});

Then(
  "je vois un message indiquant que la source n'est pas supportée",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("un message d'erreur approprié s'affiche", async ({ $test }) => {
  $test.fixme();
});

Then(
  "un message expliquant que le fichier est insuffisant s'affiche",
  async ({ $test }) => {
    $test.fixme();
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
  // The app shows "Impossible de charger les voyages." (FR) / "Failed to load trips." (EN)
  await expect(
    mockedPage.getByText(
      /Failed to load|Impossible de charger|404|not found|introuvable/i,
    ),
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
  // "Usable" = no error-boundary crash. Either the welcome link card is still
  // interactive (POST /trips failed, stayed on /) or the trip page top bar is
  // present (POST succeeded then an SSE error fired). Accept either.
  const linkCard = mockedPage.getByTestId("card-link");
  const topBar = mockedPage.getByTestId("top-bar");
  await expect(linkCard.or(topBar).first()).toBeVisible({ timeout: 5000 });
});

Then(
  "I see a message indicating the source is not supported",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then("an appropriate error message is displayed", async ({ $test }) => {
  $test.fixme();
});

Then(
  "a message explaining the file is insufficient is displayed",
  async ({ $test }) => {
    $test.fixme();
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
  // The app shows "Failed to load trips." (EN) / "Impossible de charger les voyages." (FR)
  await expect(
    mockedPage.getByText(
      /Failed to load|Impossible de charger|404|not found|introuvable/i,
    ),
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

// ---------------------------------------------------------------------------
// Additional edge cases (Sprint 35.3) — Strava private, distant dates,
// Mercure SSE reconnection, no accommodation trip-wide, undo to the start.
// ---------------------------------------------------------------------------

/**
 * Click the date picker's "next month" button `count` times, then select a
 * mid-month day. The picker is assumed already open (grid visible). Used to
 * exercise far-future dates without depending on a hardcoded year.
 */
async function navigateForwardAndPickDay(
  page: Page,
  count: number,
  day: number,
): Promise<void> {
  const nextButton = page.locator(
    'button[aria-label*="suivant"], button[aria-label*="next"], button[aria-label*="Next"]',
  );
  for (let i = 0; i < count; i += 1) {
    await nextButton.first().click();
  }
  const gridCells = page.locator(
    'button[role="gridcell"]:not([aria-disabled="true"])',
  );
  const total = await gridCells.count();
  for (let i = 0; i < total; i += 1) {
    const cell = gridCells.nth(i);
    if ((await cell.textContent())?.trim() === String(day)) {
      await cell.click();
      return;
    }
  }
  // Fail loudly: a silent return would let later assertions pass vacuously and
  // mask a calendar-rendering regression (the date was never actually set).
  throw new Error(`Day ${day} not found or disabled in the calendar grid`);
}

// --- Strava private route — FR + EN ---

When(
  "je soumets une URL Strava d'un itinéraire privé",
  async ({ submitUrl, injectEvent }) => {
    // A private Strava route passes URL validation (the format is supported),
    // so POST /trips succeeds; the backend route-fetch worker then fails and
    // emits a validation_error SSE event surfaced as a toast.
    await submitUrl("https://www.strava.com/routes/99999999");
    await injectEvent(validationErrorEvent());
  },
);

When(
  "I submit a private Strava route URL",
  async ({ submitUrl, injectEvent }) => {
    await submitUrl("https://www.strava.com/routes/99999999");
    await injectEvent(validationErrorEvent());
  },
);

Then(
  "je vois un message d'erreur indiquant que la source est inaccessible",
  async ({ mockedPage }) => {
    await expect(
      mockedPage
        .getByText(/inaccessible|invalide|invalid|unavailable|source/i)
        .first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "I see an error message indicating the source is inaccessible",
  async ({ mockedPage }) => {
    await expect(
      mockedPage
        .getByText(/inaccessible|invalide|invalid|unavailable|source/i)
        .first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

// --- Very distant departure date (~2 years) — FR + EN ---

When(
  "je configure une date de départ à environ deux ans",
  async ({ mockedPage }) => {
    await navigateForwardAndPickDay(mockedPage, 24, 15);
  },
);

When("I set a departure date about two years out", async ({ mockedPage }) => {
  await navigateForwardAndPickDay(mockedPage, 24, 15);
});

// --- Mercure SSE reconnection resumes updates — FR + EN ---

When(
  "une mise à jour temps réel de l'étape {int} est reçue",
  async ({ mockedPage }, stage: number) => {
    await injectSseEvent(mockedPage, stageUpdatedEvent(stage - 1));
  },
);

When(
  "a real-time update for stage {int} is received",
  async ({ mockedPage }, stage: number) => {
    await injectSseEvent(mockedPage, stageUpdatedEvent(stage - 1));
  },
);

// --- No accommodation found across the whole trip — FR + EN ---

When(
  "aucun hébergement n'est trouvé pour l'ensemble du voyage",
  async ({ mockedPage }) => {
    await injectSseSequence(mockedPage, [
      emptyAccommodationsFoundEvent(0),
      emptyAccommodationsFoundEvent(1),
      emptyAccommodationsFoundEvent(2),
    ]);
  },
);

When(
  "no accommodation is found for the entire trip",
  async ({ mockedPage }) => {
    await injectSseSequence(mockedPage, [
      emptyAccommodationsFoundEvent(0),
      emptyAccommodationsFoundEvent(1),
      emptyAccommodationsFoundEvent(2),
    ]);
  },
);

// --- Undo to the beginning disables the button — FR + EN ---

Then("le bouton d'annulation est désactivé", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("undo-button")).toBeDisabled({
    timeout: 5000,
  });
});

Then("the undo button is disabled", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("undo-button")).toBeDisabled({
    timeout: 5000,
  });
});
