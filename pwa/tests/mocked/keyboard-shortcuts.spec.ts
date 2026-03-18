import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Keyboard shortcuts", () => {
  test("? key opens the help modal", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await mockedPage.keyboard.press("?");
    await expect(
      mockedPage.getByTestId("keyboard-help-modal"),
    ).toBeVisible();
  });

  test("? key closes the help modal when already open", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Open
    await mockedPage.keyboard.press("?");
    await expect(mockedPage.getByTestId("keyboard-help-modal")).toBeVisible();
    // Close
    await mockedPage.keyboard.press("?");
    await expect(
      mockedPage.getByTestId("keyboard-help-modal"),
    ).not.toBeVisible();
  });

  test("Escape closes the help modal when open", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await mockedPage.keyboard.press("?");
    await expect(mockedPage.getByTestId("keyboard-help-modal")).toBeVisible();
    await mockedPage.keyboard.press("Escape");
    await expect(
      mockedPage.getByTestId("keyboard-help-modal"),
    ).not.toBeVisible();
  });

  test("Escape closes the config panel when open and help modal is not open", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    const dialog = mockedPage.getByRole("dialog", { name: "Paramètres" });
    await expect(dialog).toBeInViewport();
    await mockedPage.keyboard.press("Escape");
    await expect(dialog).not.toBeInViewport();
  });

  test("J key navigates to the next stage when a trip is loaded", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Initially focused index should be null
    const initialIndex = await mockedPage.evaluate(() => {
      const store = (
        window as Window & {
          __zustand_ui_store?: {
            getState: () => { focusedMapStageIndex: number | null };
          };
        }
      ).__zustand_ui_store;
      return store?.getState().focusedMapStageIndex ?? null;
    });
    expect(initialIndex).toBeNull();

    // Press J — should focus stage 0
    await mockedPage.locator("body").press("j");
    const afterFirstJ = await mockedPage.evaluate(() => {
      const store = (
        window as Window & {
          __zustand_ui_store?: {
            getState: () => { focusedMapStageIndex: number | null };
          };
        }
      ).__zustand_ui_store;
      return store?.getState().focusedMapStageIndex ?? null;
    });
    expect(afterFirstJ).toBe(0);

    // Press J again — should focus stage 1
    await mockedPage.locator("body").press("j");
    const afterSecondJ = await mockedPage.evaluate(() => {
      const store = (
        window as Window & {
          __zustand_ui_store?: {
            getState: () => { focusedMapStageIndex: number | null };
          };
        }
      ).__zustand_ui_store;
      return store?.getState().focusedMapStageIndex ?? null;
    });
    expect(afterSecondJ).toBe(1);
  });

  test("K key navigates to the previous stage", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    // Press J twice to reach stage 1
    await mockedPage.locator("body").press("j");
    await mockedPage.locator("body").press("j");
    const afterJJ = await mockedPage.evaluate(() => {
      const store = (
        window as Window & {
          __zustand_ui_store?: {
            getState: () => { focusedMapStageIndex: number | null };
          };
        }
      ).__zustand_ui_store;
      return store?.getState().focusedMapStageIndex ?? null;
    });
    expect(afterJJ).toBe(1);

    // Press K — should go back to stage 0
    await mockedPage.locator("body").press("k");
    const afterK = await mockedPage.evaluate(() => {
      const store = (
        window as Window & {
          __zustand_ui_store?: {
            getState: () => { focusedMapStageIndex: number | null };
          };
        }
      ).__zustand_ui_store;
      return store?.getState().focusedMapStageIndex ?? null;
    });
    expect(afterK).toBe(0);
  });

  test("shortcuts are suppressed when focus is inside an <input>", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Focus the trip title input (editable-field)
    const titleField = mockedPage.getByTestId("trip-title");
    await titleField.click();
    // Press ? — should NOT open the help modal
    await mockedPage.keyboard.press("?");
    await expect(
      mockedPage.getByTestId("keyboard-help-modal"),
    ).not.toBeVisible();
  });

  test("shortcuts are suppressed when focus is inside a <select>", async ({
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
    // Open the edit form of the first accommodation to reveal the native <select>
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");
    await stageCard
      .getByRole("button", { name: "Modifier l'hébergement" })
      .first()
      .click();

    // The type select should now be visible
    const typeSelect = stageCard.getByRole("combobox", {
      name: "Type d'hébergement",
    });
    await expect(typeSelect).toBeVisible();
    await typeSelect.focus();

    // Press ? — should NOT open the help modal while a select has focus
    await mockedPage.keyboard.press("?");
    await expect(
      mockedPage.getByTestId("keyboard-help-modal"),
    ).not.toBeVisible();
  });
});
