import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
  stageUpdatedEvent,
  accommodationsFoundEvent,
} from "../fixtures/mock-data";

/**
 * Issue #326 — Acte 3 inline recomputation: shimmer skeleton + discrete progress bar.
 *
 * When the user modifies something in "Mon voyage" (Acte 3) — such as
 * selecting/deselecting an accommodation — affected stage cards are replaced
 * by a shimmer skeleton while the backend recomputes. A thin progress bar
 * appears at the top of the page during the recomputation and disappears once
 * all `stage_updated` events have landed.
 */

test.describe("Inline recomputation — skeleton", () => {
  test("shimmer skeleton appears after a distance change", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Trigger distance change
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await stageCard
      .getByRole("button", { name: "Modifier la distance" })
      .click();
    const input = stageCard.getByRole("spinbutton", { name: "Distance (km)" });
    await input.fill("80");
    await input.press("Enter");

    // Skeleton should appear immediately after the PATCH succeeds
    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });
  });

  test("shimmer skeleton replaces stage card while stage_updated is pending", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Trigger inline recomputation by selecting an accommodation
    const stageCard = mockedPage.getByTestId("stage-card-1");
    const selectButtons = stageCard.getByRole("button", {
      name: "Sélectionner cet hébergement",
    });
    await selectButtons.first().click();

    // The skeleton should appear on the affected stage
    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });

    // The original stage card should be gone while recomputing
    await expect(mockedPage.getByTestId("stage-card-1")).toBeHidden();
  });

  test("stage card is restored after stage_updated arrives", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Trigger inline recomputation
    const selectButtons = mockedPage
      .getByTestId("stage-card-1")
      .getByRole("button", { name: "Sélectionner cet hébergement" });
    await selectButtons.first().click();

    // Wait for skeleton to appear
    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });

    // Inject stage_updated for both affected stages: selecting an accommodation
    // on stage 0 also triggers recomputation of stage 1 (its start shifts).
    await injectEvent(stageUpdatedEvent(0));
    await injectEvent(stageUpdatedEvent(1));

    // The real stage card should be back
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 3000,
    });

    // The skeleton should be gone
    await expect(mockedPage.getByTestId("stage-skeleton")).toBeHidden();
  });

  test("skeleton preserves approximate card dimensions (no layout shift)", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Measure the card before recomputation
    const cardBefore = await mockedPage
      .getByTestId("stage-card-1")
      .boundingBox();
    expect(cardBefore).not.toBeNull();

    // Trigger recomputation
    const selectButtons = mockedPage
      .getByTestId("stage-card-1")
      .getByRole("button", { name: "Sélectionner cet hébergement" });
    await selectButtons.first().click();

    // Measure the skeleton
    await expect(mockedPage.getByTestId("stage-skeleton").first()).toBeVisible({
      timeout: 3000,
    });
    const skeletonBox = await mockedPage
      .getByTestId("stage-skeleton")
      .first()
      .boundingBox();
    expect(skeletonBox).not.toBeNull();

    // Width should be the same (same max-width constraints apply to both)
    expect(skeletonBox!.width).toBeCloseTo(cardBefore!.width, -1);
  });
});

test.describe("Inline recomputation — progress bar", () => {
  test("progress bar appears when a recomputation starts", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Bar should not be visible before any recomputation
    await expect(
      mockedPage.getByTestId("inline-recomputation-bar"),
    ).toBeHidden();

    // Trigger recomputation via accommodation selection
    const selectButtons = mockedPage
      .getByTestId("stage-card-1")
      .getByRole("button", { name: "Sélectionner cet hébergement" });
    await selectButtons.first().click();

    // Bar should appear
    await expect(
      mockedPage.getByTestId("inline-recomputation-bar"),
    ).toBeVisible({ timeout: 3000 });
  });

  test("progress bar disappears after all stage_updated events land", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Trigger recomputation
    const selectButtons = mockedPage
      .getByTestId("stage-card-1")
      .getByRole("button", { name: "Sélectionner cet hébergement" });
    await selectButtons.first().click();

    await expect(
      mockedPage.getByTestId("inline-recomputation-bar"),
    ).toBeVisible({ timeout: 3000 });

    // Inject stage_updated for both affected stages (0 and 1)
    await injectEvent(stageUpdatedEvent(0));
    await injectEvent(stageUpdatedEvent(1));

    // Bar should disappear once recomputingStages is empty
    await expect(mockedPage.getByTestId("inline-recomputation-bar")).toBeHidden(
      { timeout: 3000 },
    );
  });

  test("progress bar is not visible outside Acte 3 (no trip loaded)", async ({
    mockedPage,
  }) => {
    // On the welcome screen there is no inline recomputation bar
    await expect(
      mockedPage.getByTestId("inline-recomputation-bar"),
    ).toBeHidden();
  });
});
