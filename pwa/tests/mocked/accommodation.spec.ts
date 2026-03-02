import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Accommodations", () => {
  test("shows accommodations from SSE events", async ({
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
    // Check accommodation type labels
    await expect(stageCard).toContainText("Camping");
    await expect(stageCard).toContainText("Hôtel");
  });

  test("adds manual accommodation", async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    const stageCard = mockedPage.getByTestId("stage-card-1");
    // Click "Ajouter un hébergement"
    await stageCard
      .getByRole("button", { name: "Ajouter un hébergement" })
      .click();
    // Form should appear with URL input focused
    const nameInput = stageCard.getByRole("textbox", {
      name: "Nom de l'hébergement",
    });
    await expect(nameInput).toBeVisible();
  });

  test("removes accommodation", async ({
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
    // Click remove button on first accommodation
    const removeButtons = stageCard.getByRole("button", {
      name: "Supprimer l'hébergement",
    });
    await removeButtons.first().click();
    await expect(stageCard).not.toContainText("Camping Les Oliviers");
  });

  test("no accommodation panel on last stage", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const lastStage = mockedPage.getByTestId("stage-card-3");
    await expect(lastStage).toBeVisible();
    // Last stage should not have the "Ajouter un hébergement" button
    await expect(
      lastStage.getByRole("button", { name: "Ajouter un hébergement" }),
    ).toBeHidden();
  });
});
