import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent, stagesComputedEvent } from "../fixtures/mock-data";

/**
 * Acte 1.5 — Preview screen (issue #321).
 *
 * Simulates the two-phase pipeline (ADR-027): after Phase 1 (pacing engine)
 * completes the frontend sits on the preview screen; the user then clicks
 * "Lancer l'analyse" to trigger Phase 2 (`POST /trips/{id}/analyze`).
 *
 * Phase 1 completion is simulated with `route_parsed` + `stages_computed`
 * SSE events; the processing/analysisStarted flags are flipped via the
 * `__test_set_processing` / `__test_set_analysis_started` CustomEvents
 * wired into TripPlanner (these work in prod builds where the dev-only
 * `window.__zustand_ui_store` handle is stripped by NODE_ENV).
 */
async function enterPreviewState(
  submitUrl: () => Promise<void>,
  injectEvent: (
    event: import("../../src/lib/mercure/types").MercureEvent,
  ) => Promise<void>,
  mockedPage: import("@playwright/test").Page,
): Promise<void> {
  await submitUrl();
  await injectEvent(routeParsedEvent());
  await injectEvent(stagesComputedEvent());
  // Simulate the Phase 1 → Phase 2 gate: computation has settled but the
  // user has not yet launched the analysis.
  await mockedPage.evaluate(() => {
    window.dispatchEvent(
      new CustomEvent("__test_set_processing", { detail: false }),
    );
    window.dispatchEvent(
      new CustomEvent("__test_set_analysis_started", { detail: false }),
    );
  });
  await expect(mockedPage.getByTestId("trip-preview")).toBeVisible({
    timeout: 5000,
  });
}

test.describe("Trip preview — display", () => {
  test("shows map, stats and stage breakdown after Phase 1 completes", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterPreviewState(submitUrl, injectEvent, mockedPage);

    // Map + elevation profile container
    await expect(mockedPage.getByTestId("trip-preview-map")).toBeVisible();

    // Global stats (reused TripSummary)
    await expect(mockedPage.getByTestId("total-distance")).toBeVisible();
    await expect(mockedPage.getByTestId("total-elevation")).toBeVisible();

    // Stage breakdown — 3 stages in the mock fixture
    await expect(
      mockedPage.getByTestId("trip-preview-stages-count"),
    ).toContainText("3");
    await expect(mockedPage.getByTestId("trip-preview-stage-0")).toBeVisible();
    await expect(mockedPage.getByTestId("trip-preview-stage-1")).toBeVisible();
    await expect(mockedPage.getByTestId("trip-preview-stage-2")).toBeVisible();
  });

  test("stepper shows 'preview' as the active step", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterPreviewState(submitUrl, injectEvent, mockedPage);
    await expect(
      mockedPage.getByTestId("stepper-step-preview"),
    ).toHaveAttribute("aria-current", "step", { timeout: 5000 });
  });
});

test.describe("Trip preview — actions", () => {
  test("'Lancer l'analyse' calls POST /trips/{id}/analyze and moves to Acte 2", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    let analyzeRequested = false;
    await mockedPage.route("**/trips/*/analyze", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      analyzeRequested = true;
      return route.fulfill({
        status: 202,
        contentType: "application/ld+json",
        body: JSON.stringify({ "@type": "Trip" }),
      });
    });

    await enterPreviewState(submitUrl, injectEvent, mockedPage);
    await mockedPage.getByTestId("trip-preview-launch-analysis").click();

    // Request was issued
    await expect.poll(() => analyzeRequested, { timeout: 5000 }).toBe(true);

    // Preview disappears and stepper advances to "analysis"
    await expect(mockedPage.getByTestId("trip-preview")).toBeHidden({
      timeout: 5000,
    });
    await expect(
      mockedPage.getByTestId("stepper-step-analysis"),
    ).toHaveAttribute("aria-current", "step", { timeout: 5000 });
  });

  test("'Modifier les paramètres' opens the ConfigPanel sidebar", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterPreviewState(submitUrl, injectEvent, mockedPage);

    await mockedPage.getByTestId("trip-preview-modify-parameters").click();

    // ConfigPanel is a dialog — its title appears when the panel slides in.
    await expect(
      mockedPage.getByRole("dialog", { name: /paramètres|settings/i }),
    ).toBeVisible({ timeout: 5000 });
  });

  test("'Changer d'itinéraire' resets the trip and returns to Acte 1", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterPreviewState(submitUrl, injectEvent, mockedPage);

    await mockedPage.getByTestId("trip-preview-change-route").click();

    // Back to the welcome screen (card selection is rendered)
    await expect(mockedPage.getByTestId("card-selection")).toBeVisible({
      timeout: 5000,
    });
    // And the stepper rewinds to "preparation"
    await expect(
      mockedPage.getByTestId("stepper-step-preparation"),
    ).toHaveAttribute("aria-current", "step", { timeout: 5000 });
  });

  test("'Lancer l'analyse' surfaces a toast when the request fails", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await mockedPage.route("**/trips/*/analyze", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 500,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@type": "hydra:Error",
          "hydra:title": "Server Error",
        }),
      });
    });

    await enterPreviewState(submitUrl, injectEvent, mockedPage);
    await mockedPage.getByTestId("trip-preview-launch-analysis").click();

    // An error toast surfaces and the user stays on the preview screen
    await expect(
      mockedPage.getByText(
        /impossible de lancer l'analyse|failed to launch analysis/i,
      ),
    ).toBeVisible({ timeout: 5000 });
    await expect(mockedPage.getByTestId("trip-preview")).toBeVisible();
  });
});
