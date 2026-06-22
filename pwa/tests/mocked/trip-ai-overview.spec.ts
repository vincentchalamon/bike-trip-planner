import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripReadyEvent,
  tripReadyEventWithAiOverview,
} from "../fixtures/mock-data";

/**
 * Issue #305 — Trip-level AI overview card.
 *
 * The {@link TripAiOverview} component renders the narrative, patterns,
 * recommendations and cross-stage alerts produced by the LLaMA pass 2 at
 * the top of "Mon voyage" (Acte 3). It must:
 *   - render when `trip_ready` carries a populated `aiOverview` payload;
 *   - render nothing at all when `aiOverview` is `null` (silent fallback);
 *   - on mobile, hide the detailed sections behind a disclosure toggle.
 */

test.describe("TripAiOverview — display", () => {
  test("renders the AI overview card when aiOverview is present", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithAiOverview());

    const overview = mockedPage.getByTestId("trip-ai-overview");
    await expect(overview).toBeVisible({ timeout: 10000 });

    // Narrative teaser is always visible.
    await expect(
      mockedPage.getByTestId("trip-ai-overview-teaser"),
    ).toContainText(/traversée de l'Ardèche/i);

    // Patterns, recommendations, and cross-stage alerts are rendered as lists.
    await expect(
      mockedPage.getByTestId("trip-ai-overview-patterns"),
    ).toContainText(/Dénivelé positif majoritairement/i);
    await expect(
      mockedPage.getByTestId("trip-ai-overview-recommendations"),
    ).toContainText(/Démarrer tôt le jour 1/i);
    await expect(
      mockedPage.getByTestId("trip-ai-overview-cross-stage-alerts"),
    ).toContainText(/Charge cumulative supérieure/i);
  });

  test("renders nothing when aiOverview is null (silent fallback)", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    // The canonical tripReadyEvent fixture has `aiOverview: null`.
    await injectEvent(tripReadyEvent());

    // Wait for the trip to render so we know the card SHOULD have appeared
    // if the overview existed — then assert it does not.
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });
    await expect(mockedPage.getByTestId("trip-ai-overview")).toHaveCount(0);
  });
});

test.describe("TripAiOverview — re-analysis", () => {
  test("clears the stale overview when a new analysis is launched", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    // First analysis — overview appears.
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithAiOverview());

    await expect(mockedPage.getByTestId("trip-ai-overview")).toBeVisible({
      timeout: 10_000,
    });

    // Simulate the user triggering a fresh analysis from a downstream flow
    // (batch refinement, etc.): `handleLaunchAnalysis` must clear the stale
    // overview synchronously so the card does not show outdated data while
    // the new pipeline is in flight. The CustomEvent hook works in production
    // builds (unlike `window.__zustand_trip_store` which is guarded by NODE_ENV).
    await mockedPage.evaluate(() => {
      window.dispatchEvent(new CustomEvent("__test_clear_ai_overview"));
    });

    await expect(mockedPage.getByTestId("trip-ai-overview")).toHaveCount(0);

    // A new `trip_ready` without an overview keeps the card absent.
    await injectEvent(tripReadyEvent());
    await expect(mockedPage.getByTestId("trip-ai-overview")).toHaveCount(0);
  });
});

test.describe("TripAiOverview — per-block async states (ADR-043)", () => {
  test("shows a skeleton while the AI block is running, then the card when trip_ready lands", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());

    // Structural stages already render the trip view.
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Configure AI + mark the AI block running → loading skeleton appears.
    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_ai_capability", {
          detail: { available: true, configured: true },
        }),
      );
      window.dispatchEvent(
        new CustomEvent("__test_set_block_status", {
          detail: { ai: "running" },
        }),
      );
    });

    await expect(
      mockedPage.getByTestId("trip-ai-overview-loading"),
    ).toBeVisible();

    // trip_ready brings the overview and flips the block to done.
    await injectEvent(tripReadyEventWithAiOverview());

    await expect(mockedPage.getByTestId("trip-ai-overview")).toBeVisible();
    await expect(
      mockedPage.getByTestId("trip-ai-overview-loading"),
    ).toHaveCount(0);
  });

  test("shows an error + regenerate button when the AI block fails", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEvent());

    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_ai_capability", {
          detail: { available: true, configured: true },
        }),
      );
      window.dispatchEvent(
        new CustomEvent("__test_set_block_status", {
          detail: { ai: "failed" },
        }),
      );
    });

    await expect(
      mockedPage.getByTestId("trip-ai-overview-failed"),
    ).toBeVisible();
    await expect(
      mockedPage.getByTestId("trip-ai-overview-regenerate"),
    ).toBeVisible();
  });
});

test.describe("TripAiOverview — responsive", () => {
  test("collapses detailed sections behind a disclosure toggle on mobile", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    // Switch to a mobile viewport so the `md:hidden` toggle becomes visible
    // and the `hidden md:flex` details block is collapsed by default.
    await mockedPage.setViewportSize({ width: 375, height: 800 });

    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithAiOverview());

    const overview = mockedPage.getByTestId("trip-ai-overview");
    await expect(overview).toBeVisible({ timeout: 10000 });

    const toggle = mockedPage.getByTestId("trip-ai-overview-toggle");
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute("aria-expanded", "false");

    // Details block is hidden in the collapsed state on mobile.
    await expect(
      mockedPage.getByTestId("trip-ai-overview-details"),
    ).toBeHidden();

    await toggle.click();

    await expect(toggle).toHaveAttribute("aria-expanded", "true");
    await expect(
      mockedPage.getByTestId("trip-ai-overview-details"),
    ).toBeVisible();
    await expect(
      mockedPage.getByTestId("trip-ai-overview-recommendations"),
    ).toBeVisible();
  });
});
