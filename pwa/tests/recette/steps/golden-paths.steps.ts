import { expect, type Page } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import { stageUpdatedEventWithSelectedAccommodation } from "../../fixtures/mock-data";
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
 * to `/trips/gpx-upload` (mocked, 202 + trip body), then — like the magic-link
 * flow (#729) — navigates to /trips/{id}, where the planner re-hydrates from the
 * detail endpoint and mounts the Mercure subscriber so injected SSE events are
 * processed. Mirrors `tests/mocked/gpx-upload.spec.ts`.
 */
async function importGpxFile(page: Page): Promise<void> {
  await page.route("**/trips/gpx-upload", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 202,
      contentType: "application/json",
      body: JSON.stringify({
        "@context": "/contexts/Trip",
        "@id": "/trips/test-trip-abc-123",
        "@type": "Trip",
        id: "test-trip-abc-123",
        computationStatus: { route: "done", stages: "pending" },
        title: "Mon Tour GPX",
        totalDistance: 187.3,
        totalElevation: 2850,
        totalElevationLoss: 2720,
      }),
    });
  });
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
  // A successful upload navigates to /trips/{id}; wait for the URL change then
  // the trip title rendered after the detail load.
  await page.waitForURL(/\/trips\//, { timeout: 10000 });
  await expect(
    page.getByTestId("trip-title-skeleton").or(page.getByTestId("trip-title")),
  ).toBeVisible({ timeout: 10000 });
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

// --- Geometry injection (elevation profile needs a real trace) FR + EN ---

When(
  "les étapes calculées contiennent un tracé géométrique",
  async ({ injectEvent }) => {
    const { stagesComputedEventWithGeometry } =
      await import("../../fixtures/mock-data");
    await injectEvent(stagesComputedEventWithGeometry());
  },
);

When("the computed stages contain geometry data", async ({ injectEvent }) => {
  const { stagesComputedEventWithGeometry } =
    await import("../../fixtures/mock-data");
  await injectEvent(stagesComputedEventWithGeometry());
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
