import { expect, type Page } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  routeParsedEvent,
  stageUpdatedEventWithSelectedAccommodation,
} from "../../fixtures/mock-data";
import { injectSseEvent } from "../../fixtures/sse-helpers";
import { expandGpxCard } from "../../fixtures/base.fixture";

// ---------------------------------------------------------------------------
// Golden paths A/B/C — only steps not already defined in other step files.
// Reuses the shared fixtures (submitUrl, createFullTrip, injectSequence) and
// the generic backbone from common.steps.ts wherever possible.
// ---------------------------------------------------------------------------

const SELECT_ACCOMMODATION_NAME =
  /Sélectionner cet hébergement|Select accommodation/;

/**
 * Locate the accommodation item carrying the given name within a stage card,
 * click its select toggle, then push the matching `stage_updated` SSE event so
 * the store reflects the selected accommodation (endpoint recomputation).
 */
async function selectAccommodation(
  page: Page,
  name: string,
  stage: number,
): Promise<void> {
  const stageCard = page.getByTestId(`stage-card-${stage}`);
  const item = stageCard
    .getByTestId("accommodation-item")
    .filter({ hasText: name });
  await expect(item).toBeVisible({ timeout: 10000 });
  await item.getByRole("button", { name: SELECT_ACCOMMODATION_NAME }).click();
  // The endpoint recomputation arrives via SSE; inject the matching event for
  // the 0-based stage index so the selected badge renders deterministically.
  await injectSseEvent(
    page,
    stageUpdatedEventWithSelectedAccommodation(stage - 1),
  );
}

/**
 * Upload a valid GPX file through the welcome-screen GPX card. The upload POSTs
 * to /trips (mocked) and seeds the store via `setTrip`, which mounts the Mercure
 * subscriber so injected SSE events are processed. Unlike the magic-link flow,
 * a GPX upload does not navigate to /trips/{id} — the planner renders in place.
 */
async function importGpxFile(page: Page): Promise<void> {
  await expandGpxCard(page);
  await expect(page.getByTestId("card-gpx")).toBeVisible({ timeout: 5000 });
  await page.getByTestId("gpx-file-input").setInputFiles({
    name: "tour-ardeche.gpx",
    mimeType: "application/gpx+xml",
    buffer: Buffer.from(
      '<?xml version="1.0"?><gpx><trk><trkseg>' +
        '<trkpt lat="44.735" lon="4.598"><ele>280</ele></trkpt>' +
        '<trkpt lat="44.532" lon="4.392"><ele>540</ele></trkpt>' +
        "</trkseg></trk></gpx>",
    ),
  });
  // route_parsed sets the title, proving the trip is in the store and the SSE
  // pipeline is live before the scenario injects the remaining events.
  await injectSseEvent(page, routeParsedEvent());
  await expect(page.getByTestId("trip-title")).toBeVisible({ timeout: 10000 });
}

// --- Given steps FR ---

Given(
  "je crée un voyage complet depuis {string}",
  async ({ submitUrl, injectSequence, mockedPage }, url: string) => {
    const { fullTripEventSequence } = await import("../../fixtures/mock-data");
    await submitUrl(url);
    await injectSequence(fullTripEventSequence());
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
      timeout: 10000,
    });
  },
);

Given(
  "je crée un voyage en important un fichier GPX",
  async ({ mockedPage }) => {
    await importGpxFile(mockedPage);
  },
);

// --- Given steps EN ---

Given(
  "I create a full trip from {string}",
  async ({ submitUrl, injectSequence, mockedPage }, url: string) => {
    const { fullTripEventSequence } = await import("../../fixtures/mock-data");
    await submitUrl(url);
    await injectSequence(fullTripEventSequence());
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
      timeout: 10000,
    });
  },
);

Given("I create a trip by importing a GPX file", async ({ mockedPage }) => {
  await importGpxFile(mockedPage);
});

// --- When steps FR ---

When(
  "je sélectionne l'hébergement {string} de l'étape {int}",
  async ({ mockedPage }, name: string, stage: number) => {
    await selectAccommodation(mockedPage, name, stage);
  },
);

// --- When steps EN ---

When(
  "I select accommodation {string} for stage {int}",
  async ({ mockedPage }, name: string, stage: number) => {
    await selectAccommodation(mockedPage, name, stage);
  },
);

// --- Then steps FR ---

Then(
  "l'hébergement {string} est marqué comme sélectionné pour l'étape {int}",
  async ({ mockedPage }, name: string, stage: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const item = stageCard
      .getByTestId("accommodation-item")
      .filter({ hasText: name });
    await expect(item).toContainText(/Sélectionné|Selected/, { timeout: 5000 });
  },
);

// --- Then steps EN ---

Then(
  "accommodation {string} is marked as selected for stage {int}",
  async ({ mockedPage }, name: string, stage: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${stage}`);
    const item = stageCard
      .getByTestId("accommodation-item")
      .filter({ hasText: name });
    await expect(item).toContainText(/Sélectionné|Selected/, { timeout: 5000 });
  },
);
