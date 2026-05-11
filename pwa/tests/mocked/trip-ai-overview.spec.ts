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
