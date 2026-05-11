import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripReadyEvent,
  tripReadyEventWithStageAiAnalysis,
} from "../fixtures/mock-data";

/**
 * Issue #306 — Per-stage AI summary + hybrid alerts layout.
 *
 * The {@link StageAiSummary} component renders the LLaMA pass-1 narrative +
 * insights + suggestions for each stage and collapses the detailed alerts
 * behind a top-3 preview. The "Apply N suggestions" CTA enqueues a `pacing`
 * batch modification so the recompute is folded into the existing batch flow.
 *
 * Coverage:
 *   - Summary visible for a stage that ships `aiAnalysis` and absent for one
 *     that does not (silent fallback to the legacy {@link StageAlerts}).
 *   - Alerts are collapsed by default when AI is present, expanded otherwise.
 *   - "Show more alerts" expands the full grouped {@link AlertList}.
 *   - "Apply N suggestions" pushes a modification into the queue.
 */

test.describe("StageAiSummary — display", () => {
  test("renders the AI summary card for stages that ship aiAnalysis", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithStageAiAnalysis());

    const summary = mockedPage.getByTestId("stage-ai-summary-0");
    await expect(summary).toBeVisible({ timeout: 10000 });

    await expect(
      mockedPage.getByTestId("stage-ai-summary-narrative"),
    ).toContainText(/Journée exigeante/i);
    await expect(
      mockedPage.getByTestId("stage-ai-summary-insights"),
    ).toContainText(/Pente moyenne/i);
    await expect(
      mockedPage.getByTestId("stage-ai-summary-suggestions"),
    ).toContainText(/Démarrer tôt/i);
  });

  test("falls back silently to legacy alerts when aiAnalysis is null", async ({
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
    await expect(mockedPage.getByTestId("stage-ai-summary-0")).toHaveCount(0);
  });
});

test.describe("StageAiSummary — hybrid alerts", () => {
  test("collapses alerts by default and shows a top-3 preview with a 'show more' affordance", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithStageAiAnalysis());

    await expect(mockedPage.getByTestId("stage-ai-summary-0")).toBeVisible({
      timeout: 10000,
    });

    // Collapsed by default: preview surfaces the top 3 alerts ordered by
    // severity (critical → warning → nudge) — the seeded stage carries
    // 7 alerts so 4 should remain hidden behind the disclosure.
    const preview = mockedPage.getByTestId("stage-ai-summary-alerts-preview");
    await expect(preview).toBeVisible();
    await expect(
      mockedPage.getByTestId("stage-ai-summary-alerts-show-more"),
    ).toContainText(/4/);

    const toggle = mockedPage.getByTestId("stage-ai-summary-alerts-toggle");
    await expect(toggle).toHaveAttribute("aria-expanded", "false");

    await mockedPage.getByTestId("stage-ai-summary-alerts-show-more").click();

    await expect(toggle).toHaveAttribute("aria-expanded", "true");
    await expect(
      mockedPage.getByTestId("stage-ai-summary-alerts-full"),
    ).toBeVisible();
  });

  test("expands alerts via the section toggle as well", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithStageAiAnalysis());

    const toggle = mockedPage.getByTestId("stage-ai-summary-alerts-toggle");
    await expect(toggle).toBeVisible({ timeout: 10000 });
    await expect(toggle).toHaveAttribute("aria-expanded", "false");

    await toggle.click();

    await expect(toggle).toHaveAttribute("aria-expanded", "true");
    await expect(
      mockedPage.getByTestId("stage-ai-summary-alerts-full"),
    ).toBeVisible();
  });

  test("renders the legacy fully-expanded alerts for stages with no AI analysis", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithStageAiAnalysis());

    // The roadbook master/detail renders all stage cards side by side; stage 2
    // (index 1) carries `aiAnalysis: null` so it must fall back to the legacy
    // `StageAlerts` (no hybrid summary block).
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible({
      timeout: 10000,
    });
    await expect(mockedPage.getByTestId("stage-ai-summary-1")).toHaveCount(0);
  });
});

test.describe("StageAiSummary — apply suggestions", () => {
  test("'Apply suggestions' enqueues a batch modification", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await injectEvent(tripReadyEventWithStageAiAnalysis());

    await expect(mockedPage.getByTestId("stage-ai-summary-apply")).toBeVisible({
      timeout: 10000,
    });

    // Queue starts empty.
    await expect(mockedPage.getByTestId("modification-queue")).toBeHidden();

    await mockedPage.getByTestId("stage-ai-summary-apply").click();

    // The CTA pushes a `pacing` modification → the floating queue surfaces.
    await expect(mockedPage.getByTestId("modification-queue")).toBeVisible({
      timeout: 3000,
    });
  });
});
