import { test, expect } from "../fixtures/base.fixture";

test.describe("Undo/Redo", () => {
  test("undo and redo buttons are disabled initially", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await expect(mockedPage.getByTestId("undo-button")).toBeDisabled();
    await expect(mockedPage.getByTestId("redo-button")).toBeDisabled();
  });

  test("undo button becomes enabled after a successful stage distance edit", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const stageCard = mockedPage.getByTestId("stage-card-1");
    // Open distance editor
    await stageCard
      .getByRole("button", { name: "Modifier la distance" })
      .click();
    const distanceInput = stageCard.getByRole("spinbutton", {
      name: "Distance (km)",
    });
    await expect(distanceInput).toBeVisible();
    await distanceInput.fill("80");
    // Save via Enter key
    await distanceInput.press("Enter");
    // After successful PATCH, undo should be enabled
    await expect(mockedPage.getByTestId("undo-button")).toBeEnabled({
      timeout: 5000,
    });
  });

  test("clicking undo disables undo button and enables redo", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const stageCard = mockedPage.getByTestId("stage-card-1");
    // Trigger an undo point
    await stageCard
      .getByRole("button", { name: "Modifier la distance" })
      .click();
    const distanceInput = stageCard.getByRole("spinbutton", {
      name: "Distance (km)",
    });
    await distanceInput.fill("80");
    await distanceInput.press("Enter");
    await expect(mockedPage.getByTestId("undo-button")).toBeEnabled({
      timeout: 5000,
    });
    // Click undo
    await mockedPage.getByTestId("undo-button").click();
    // Undo stack should now be empty (only 1 action pushed)
    await expect(mockedPage.getByTestId("undo-button")).toBeDisabled();
    // Redo should now be enabled
    await expect(mockedPage.getByTestId("redo-button")).toBeEnabled();
  });

  test("Ctrl+Z is suppressed when an input is focused", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const stageCard = mockedPage.getByTestId("stage-card-1");
    // Trigger an undo point
    await stageCard
      .getByRole("button", { name: "Modifier la distance" })
      .click();
    const distanceInput = stageCard.getByRole("spinbutton", {
      name: "Distance (km)",
    });
    await distanceInput.fill("80");
    await distanceInput.press("Enter");
    await expect(mockedPage.getByTestId("undo-button")).toBeEnabled({
      timeout: 5000,
    });
    // Open the distance editor again — input is auto-focused
    await stageCard
      .getByRole("button", { name: "Modifier la distance" })
      .click();
    await expect(
      stageCard.getByRole("spinbutton", { name: "Distance (km)" }),
    ).toBeFocused();
    // Press Ctrl+Z while input is focused — should be suppressed
    await mockedPage.keyboard.press("Control+z");
    // Undo button must still be enabled (undo was NOT executed)
    await expect(mockedPage.getByTestId("undo-button")).toBeEnabled();
  });

  test("Ctrl+Shift+Z (redo) works after undo", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const stageCard = mockedPage.getByTestId("stage-card-1");
    // Trigger an undo point
    await stageCard
      .getByRole("button", { name: "Modifier la distance" })
      .click();
    const distanceInput = stageCard.getByRole("spinbutton", {
      name: "Distance (km)",
    });
    await distanceInput.fill("80");
    await distanceInput.press("Enter");
    await expect(mockedPage.getByTestId("undo-button")).toBeEnabled({
      timeout: 5000,
    });
    // Undo via keyboard
    await mockedPage.keyboard.press("Control+z");
    await expect(mockedPage.getByTestId("redo-button")).toBeEnabled({
      timeout: 5000,
    });
    // Redo via Ctrl+Shift+Z
    await mockedPage.keyboard.press("Control+Shift+Z");
    // After redo, undo should be enabled again, redo disabled
    await expect(mockedPage.getByTestId("undo-button")).toBeEnabled({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("redo-button")).toBeDisabled();
  });

  test("history is cleared after submitting a new URL (clearTrip)", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const stageCard = mockedPage.getByTestId("stage-card-1");
    // Trigger an undo point
    await stageCard
      .getByRole("button", { name: "Modifier la distance" })
      .click();
    const distanceInput = stageCard.getByRole("spinbutton", {
      name: "Distance (km)",
    });
    await distanceInput.fill("80");
    await distanceInput.press("Enter");
    await expect(mockedPage.getByTestId("undo-button")).toBeEnabled({
      timeout: 5000,
    });
    // Submit a new URL — this internally calls clearTrip() which wipes history
    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/9999999999");
    await input.press("Enter");
    // Wait for the new trip to start loading
    await expect(
      mockedPage
        .getByTestId("trip-title-skeleton")
        .or(mockedPage.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 5000 });
    // Undo/redo should be disabled now that history was cleared
    await expect(mockedPage.getByTestId("undo-button")).toBeDisabled({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("redo-button")).toBeDisabled({
      timeout: 5000,
    });
  });
});
