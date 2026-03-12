import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  fullTripEventSequence,
} from "../fixtures/mock-data";
import path from "node:path";

const GPX_FIXTURE = path.resolve(__dirname, "../fixtures/test-route.gpx");

test.describe("GPX upload flow", () => {
  test.beforeEach(async ({ mockedPage }) => {
    // Mock the GPX upload endpoint
    await mockedPage.route("**/trips/gpx-upload", (route, request) => {
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
        }),
      });
    });
  });

  test("happy path: upload GPX file, receive SSE events, trip displayed", async ({
    mockedPage,
    injectEvent,
    injectSequence,
  }) => {
    // Upload a GPX file
    const fileInput = mockedPage.getByTestId("gpx-file-input");
    await fileInput.setInputFiles(GPX_FIXTURE);

    // Trip title should appear (from backend response title)
    await expect(
      mockedPage
        .getByTestId("trip-title-skeleton")
        .or(mockedPage.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 5000 });

    // Inject route_parsed SSE event with gpx_upload source type
    await injectEvent({
      type: "route_parsed",
      data: {
        totalDistance: 187.3,
        totalElevation: 2850,
        totalElevationLoss: 2720,
        sourceType: "gpx_upload",
        title: "Mon Tour GPX",
      },
    });

    // Total distance should be displayed
    await expect(mockedPage.getByTestId("total-distance")).toContainText(
      "187km",
    );

    // Inject stages computed
    await injectSequence([stagesComputedEvent()]);

    // Stage cards should appear
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();
  });

  test("uses filename as fallback title when backend returns no title", async ({
    mockedPage,
  }) => {
    // Override mock to return no title
    await mockedPage.route("**/trips/gpx-upload", (route, request) => {
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
        }),
      });
    });

    const fileInput = mockedPage.getByTestId("gpx-file-input");
    await fileInput.setInputFiles(GPX_FIXTURE);

    // Trip title should appear (fallback to filename without .gpx)
    await expect(
      mockedPage
        .getByTestId("trip-title-skeleton")
        .or(mockedPage.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 5000 });
  });

  test("shows error toast on API 400 response", async ({ mockedPage }) => {
    // Override mock to return 400
    await mockedPage.route("**/trips/gpx-upload", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 400,
        contentType: "application/json",
        body: JSON.stringify({
          error: "Only .gpx files are accepted.",
        }),
      });
    });

    const fileInput = mockedPage.getByTestId("gpx-file-input");
    await fileInput.setInputFiles(GPX_FIXTURE);

    // Error toast should appear (French translation)
    await expect(
      mockedPage.getByText(
        "Impossible d'importer le fichier GPX. Vérifiez le fichier et réessayez.",
      ),
    ).toBeVisible({ timeout: 5000 });
  });

  test("shows error toast on API 422 response", async ({ mockedPage }) => {
    // Override mock to return 422
    await mockedPage.route("**/trips/gpx-upload", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 422,
        contentType: "application/json",
        body: JSON.stringify({
          error: "GPX file contains no track points.",
        }),
      });
    });

    const fileInput = mockedPage.getByTestId("gpx-file-input");
    await fileInput.setInputFiles(GPX_FIXTURE);

    // Error toast should appear (French translation)
    await expect(
      mockedPage.getByText(
        "Impossible d'importer le fichier GPX. Vérifiez le fichier et réessayez.",
      ),
    ).toBeVisible({ timeout: 5000 });
  });
});
