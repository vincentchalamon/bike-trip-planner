import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent, stagesComputedEvent } from "../fixtures/mock-data";

/**
 * Acte 1.5 — AI refinement card (issue #393).
 *
 * The card lives inside Step 2 (Aperçu) of the `/trips/new` wizard, alongside
 * the trip preview (`previewSlot={<AiRefinementCard />}`). To reach it the
 * test must drive the same Phase 1 → preview gate as `trip-preview.spec.ts`:
 * submit a URL, inject `route_parsed` + `stages_computed` SSE events, then
 * flip the processing/analysisStarted flags to `false` so the wizard parks
 * on the preview screen.
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

test.describe("AI refinement card", () => {
  test("card is visible on step 2", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterPreviewState(submitUrl, injectEvent, mockedPage);
    await expect(mockedPage.getByTestId("ai-refinement-card")).toBeVisible();
    await expect(
      mockedPage.getByTestId("ai-refinement-textarea"),
    ).toBeVisible();
  });

  test("Apply disabled when textarea is empty", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterPreviewState(submitUrl, injectEvent, mockedPage);
    await expect(mockedPage.getByTestId("ai-refinement-apply")).toBeDisabled();
  });

  test("Clear disabled when textarea is empty", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterPreviewState(submitUrl, injectEvent, mockedPage);
    await expect(mockedPage.getByTestId("ai-refinement-clear")).toBeDisabled();
  });

  test("Clear wipes the textarea", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterPreviewState(submitUrl, injectEvent, mockedPage);

    const textarea = mockedPage.getByTestId("ai-refinement-textarea");
    await textarea.fill("Add a stop in Ajaccio");
    await expect(textarea).toHaveValue("Add a stop in Ajaccio");

    const clearBtn = mockedPage.getByTestId("ai-refinement-clear");
    await expect(clearBtn).toBeEnabled();
    await clearBtn.click();

    await expect(textarea).toHaveValue("");
    await expect(clearBtn).toBeDisabled();
    await expect(mockedPage.getByTestId("ai-refinement-apply")).toBeDisabled();
  });

  test("Apply (stub) surfaces unavailable toast", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await enterPreviewState(submitUrl, injectEvent, mockedPage);

    await mockedPage
      .getByTestId("ai-refinement-textarea")
      .fill("Add a stop in Ajaccio");

    await mockedPage.getByTestId("ai-refinement-apply").click();

    await expect(
      mockedPage.getByText(/AI assistant coming soon|Assistant IA bientôt/i),
    ).toBeVisible({ timeout: 5000 });
  });
});
