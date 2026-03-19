import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent } from "../fixtures/mock-data";

test.describe("Keyboard shortcuts", () => {
  test("? key opens the help modal", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Defocus the magic-link input so the shortcut handler isn't suppressed
    await mockedPage.locator("body").click();
    await mockedPage.keyboard.press("?");
    await expect(mockedPage.getByTestId("keyboard-help-modal")).toBeVisible();
  });

  test("? key closes the help modal when already open", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await mockedPage.locator("body").click();
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
    await mockedPage.locator("body").click();
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
    await mockedPage.getByTestId("config-open-button").click();
    // The config panel is a dialog with aria-modal
    const configPanel = mockedPage.locator(
      '[role="dialog"][aria-modal="true"]',
    );
    await expect(configPanel).toBeInViewport();
    await mockedPage.keyboard.press("Escape");
    await expect(configPanel).not.toBeInViewport();
  });

  test("J key navigates to the next stage when a trip is loaded", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const mapContainer = mockedPage.getByTestId("map-container");

    // Initially no focused stage
    await expect(mapContainer).toHaveAttribute("data-focused-stage", "");

    // Defocus any active element before pressing navigation keys
    await mockedPage.locator("body").click();

    // Press J — should focus stage 0
    await mockedPage.keyboard.press("j");
    await expect(mapContainer).toHaveAttribute("data-focused-stage", "0");

    // Press J again — should focus stage 1
    await mockedPage.keyboard.press("j");
    await expect(mapContainer).toHaveAttribute("data-focused-stage", "1");

    // Press J to stage 2 (last stage with 3 stages)
    await mockedPage.keyboard.press("j");
    await expect(mapContainer).toHaveAttribute("data-focused-stage", "2");

    // Press J past the last stage — should return to global view
    await mockedPage.keyboard.press("j");
    await expect(mapContainer).toHaveAttribute("data-focused-stage", "");
  });

  test("K key navigates to the previous stage", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const mapContainer = mockedPage.getByTestId("map-container");

    // Defocus any active element
    await mockedPage.locator("body").click();

    // Press J twice to reach stage 1
    await mockedPage.keyboard.press("j");
    await mockedPage.keyboard.press("j");
    await expect(mapContainer).toHaveAttribute("data-focused-stage", "1");

    // Press K — should go back to stage 0
    await mockedPage.keyboard.press("k");
    await expect(mapContainer).toHaveAttribute("data-focused-stage", "0");

    // Press K before stage 0 — should return to global view
    await mockedPage.keyboard.press("k");
    await expect(mapContainer).toHaveAttribute("data-focused-stage", "");
  });

  test("K key with no focused stage jumps to the last stage", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const mapContainer = mockedPage.getByTestId("map-container");

    // Initially no focused stage
    await expect(mapContainer).toHaveAttribute("data-focused-stage", "");

    // Defocus any active element
    await mockedPage.locator("body").click();

    // Press K without any stage focused — should jump to last stage (index 2)
    await mockedPage.keyboard.press("k");
    await expect(mapContainer).toHaveAttribute("data-focused-stage", "2");
  });

  test("shortcuts are suppressed when focus is inside an <input>", async ({
    mockedPage,
  }) => {
    // Focus the magic-link input (always an <input>)
    const magicLinkInput = mockedPage.getByTestId("magic-link-input");
    await magicLinkInput.focus();
    // Press ? — should NOT open the help modal
    await mockedPage.keyboard.press("?");
    await expect(
      mockedPage.getByTestId("keyboard-help-modal"),
    ).not.toBeVisible();
  });

  test("shortcuts are suppressed when focus is inside a <select>", async ({
    mockedPage,
  }) => {
    // Inject a temporary <select> to verify the SELECT tagName guard
    await mockedPage.evaluate(() => {
      const select = document.createElement("select");
      select.id = "__test_select";
      const option = document.createElement("option");
      option.value = "a";
      option.textContent = "A";
      select.appendChild(option);
      document.body.appendChild(select);
    });
    await mockedPage.locator("#__test_select").focus();
    await mockedPage.keyboard.press("?");
    await expect(
      mockedPage.getByTestId("keyboard-help-modal"),
    ).not.toBeVisible();
  });
});
