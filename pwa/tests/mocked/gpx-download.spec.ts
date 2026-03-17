import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("GPX download", () => {
  test("GPX button enabled after stages computed", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripCompleteEvent(),
    ]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    const gpxButton = stageCard.getByRole("button", {
      name: /Télécharger le GPX/,
    });
    await expect(gpxButton).toBeEnabled();
  });

  test("GPX download triggers API fetch", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripCompleteEvent(),
    ]);

    // Track GPX API requests
    const gpxRequests: string[] = [];
    await mockedPage.route("**/trips/*/stages/*.gpx", (route) => {
      gpxRequests.push(route.request().url());
      return route.fulfill({
        status: 200,
        contentType: "application/gpx+xml",
        body: `<?xml version="1.0"?><gpx><trk><trkseg><trkpt lat="44.7" lon="4.5"><ele>280</ele></trkpt></trkseg></trk></gpx>`,
      });
    });

    const stageCard = mockedPage.getByTestId("stage-card-1");
    const gpxButton = stageCard.getByRole("button", {
      name: /Télécharger le GPX/,
    });
    await gpxButton.click();

    // Wait for the request to be made
    await expect
      .poll(() => gpxRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(gpxRequests[0]).toContain("/stages/0.gpx");
  });

  test("global GPX download button is visible after stages computed", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripCompleteEvent(),
    ]);
    const globalGpxButton = mockedPage.getByRole("button", {
      name: /Télécharger le GPX complet/,
    });
    await expect(globalGpxButton).toBeVisible();
    await expect(globalGpxButton).toBeEnabled();
  });

  test("global GPX download triggers API fetch on trip endpoint", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripCompleteEvent(),
    ]);

    // Track global GPX API requests
    const tripGpxRequests: string[] = [];
    await mockedPage.route("**/trips/*.gpx", (route) => {
      tripGpxRequests.push(route.request().url());
      return route.fulfill({
        status: 200,
        contentType: "application/gpx+xml",
        body: `<?xml version="1.0"?><gpx><trk><trkseg><trkpt lat="44.7" lon="4.5"><ele>280</ele></trkpt></trkseg></trk></gpx>`,
      });
    });

    const globalGpxButton = mockedPage.getByRole("button", {
      name: /Télécharger le GPX complet/,
    });
    await globalGpxButton.click();

    // Wait for the request to be made
    await expect
      .poll(() => tripGpxRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(tripGpxRequests[0]).toMatch(/\/trips\/[^/]+\.gpx$/);
  });
});
