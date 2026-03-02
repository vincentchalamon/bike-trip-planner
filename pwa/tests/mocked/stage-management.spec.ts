import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Stage management", () => {
  test("deletes a stage and renumbers remaining", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // 3 stages visible initially
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();
    // Delete stage 2
    await mockedPage.getByTestId("delete-stage-2").click();
    // After deletion, stages renumber: old stage 3 → stage 2. stage-card-3 disappears.
    await expect(mockedPage.getByTestId("stage-card-3")).toBeHidden({
      timeout: 5000,
    });
    // Remaining 2 stages: stage-card-1 and stage-card-2 (renumbered from old stage 3)
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
  });

  test("cannot delete when only 2 stages remain", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    // Create only 2 stages
    await injectSequence([
      routeParsedEvent(),
      {
        type: "stages_computed",
        data: {
          stages: [
            {
              dayNumber: 1,
              distance: 90,
              elevation: 1200,
              elevationLoss: 900,
              startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
              endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
              geometry: [],
              label: null,
            },
            {
              dayNumber: 2,
              distance: 97.3,
              elevation: 1650,
              elevationLoss: 1820,
              startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
              endPoint: { lat: 44.112, lon: 3.876, ele: 410 },
              geometry: [],
              label: null,
            },
          ],
        },
      },
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
    // Both delete buttons should be disabled
    await expect(mockedPage.getByTestId("delete-stage-1")).toBeDisabled();
    await expect(mockedPage.getByTestId("delete-stage-2")).toBeDisabled();
  });

  test("shows add stage button between stages", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Add stage button should exist between stage groups
    const addButtons = mockedPage.locator('[data-testid^="add-stage-button-"]');
    await expect(addButtons.first()).toBeVisible();
    await expect(addButtons.first()).toContainText("Ajouter une étape");
  });

  test("edit distance via pencil icon", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const stageCard = mockedPage.getByTestId("stage-card-1");
    // Click pencil (edit distance button)
    await stageCard
      .getByRole("button", { name: "Modifier la distance" })
      .click();
    // Distance input should appear
    const distanceInput = stageCard.getByRole("spinbutton", {
      name: "Distance (km)",
    });
    await expect(distanceInput).toBeVisible();
    await distanceInput.fill("80");
    await distanceInput.press("Enter");
    // Input should disappear
    await expect(distanceInput).toBeHidden();
  });
});
