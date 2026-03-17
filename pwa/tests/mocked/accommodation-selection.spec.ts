import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Accommodation selection", () => {
  test("select button appears on each accommodation", async ({
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
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");

    // Select buttons should be visible (circle icon)
    const selectButtons = stageCard.getByRole("button", {
      name: "Sélectionner cet hébergement",
    });
    await expect(selectButtons.first()).toBeVisible();
  });

  test("selecting an accommodation marks it as selected", async ({
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
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");

    // Click the select button on the first accommodation
    const selectButtons = stageCard.getByRole("button", {
      name: "Sélectionner cet hébergement",
    });
    await selectButtons.first().click();

    // The accommodation should now be marked as selected
    await expect(stageCard).toContainText("Sélectionné");

    // The deselect button should now appear
    await expect(
      stageCard.getByRole("button", { name: "Désélectionner l'hébergement" }),
    ).toBeVisible();

    // The estimated budget should appear in the trip summary
    await expect(mockedPage.getByTestId("estimated-budget")).toBeVisible();
    await expect(mockedPage.getByTestId("estimated-budget")).toContainText(
      "149€ — 225€",
    );
  });

  test("selecting an accommodation keeps only that accommodation", async ({
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
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");
    await expect(stageCard).toContainText("Hotel du Pont");

    // Select the first accommodation (Hotel du Pont — 0.5km, sorted first by distance)
    const selectButtons = stageCard.getByRole("button", {
      name: "Sélectionner cet hébergement",
    });
    await selectButtons.first().click();

    // Only the selected accommodation should remain (Hotel du Pont; Camping removed)
    await expect(stageCard).toContainText("Hotel du Pont");
    await expect(stageCard).not.toContainText("Camping Les Oliviers");
  });

  test("deselecting an accommodation restores deselect state", async ({
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
    const stageCard = mockedPage.getByTestId("stage-card-1");

    // Select first
    const selectButtons = stageCard.getByRole("button", {
      name: "Sélectionner cet hébergement",
    });
    await selectButtons.first().click();
    await expect(stageCard).toContainText("Sélectionné");

    // Deselect
    await stageCard
      .getByRole("button", { name: "Désélectionner l'hébergement" })
      .click();

    // "Sélectionné" badge should be gone
    await expect(stageCard).not.toContainText("Sélectionné");

    // The estimated budget is still visible (food costs remain), but no longer shows the selected accommodation amount
    await expect(
      mockedPage.getByTestId("estimated-budget"),
    ).toBeVisible();
    await expect(
      mockedPage.getByTestId("estimated-budget"),
    ).not.toContainText("225€");
  });

  test("no select button on last stage accommodation panel", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const lastStage = mockedPage.getByTestId("stage-card-3");
    // Last stage has no accommodation panel at all
    await expect(
      lastStage.getByRole("button", { name: "Sélectionner cet hébergement" }),
    ).toBeHidden();
  });
});
