import { test, expect } from "../fixtures/base.fixture";

test.describe("Undo/Redo", () => {
  test("undo and redo buttons are disabled on fresh page load", async ({
    mockedPage,
  }) => {
    // Before any trip is loaded the history stacks are empty
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
    // Click undo — must move the last action into the redo stack
    await mockedPage.getByTestId("undo-button").click();
    // Redo button must now be enabled (at least one action can be redone)
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

  test("Ctrl+Z undoes a stage deletion and restores the stage count", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Verify 3 stages loaded
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();
    // Delete stage 2
    await mockedPage.getByTestId("delete-stage-2").click();
    // Stage 3 disappears (stages renumber: old 3 → 2, so stage-card-3 is gone)
    await expect(mockedPage.getByTestId("stage-card-3")).toBeHidden({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("undo-button")).toBeEnabled({
      timeout: 5000,
    });
    // Undo — deleted stage should be restored
    await mockedPage.keyboard.press("Control+z");
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
      timeout: 5000,
    });
    // Redo button should now be enabled
    await expect(mockedPage.getByTestId("redo-button")).toBeEnabled();
  });

  test("Ctrl+Z undoes a pacing preset button change", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Open the config panel
    await mockedPage.getByTestId("config-open-button").click();
    // Click a preset button — this calls onCommit directly (no preceding onChange)
    await mockedPage.getByRole("button", { name: /Expert/ }).click();
    // The preset change should be undoable
    await expect(mockedPage.getByTestId("undo-button")).toBeEnabled({
      timeout: 5000,
    });
    // Undo — pacing should revert and redo should become available
    await mockedPage.keyboard.press("Control+z");
    await expect(mockedPage.getByTestId("redo-button")).toBeEnabled({
      timeout: 5000,
    });
  });
});
