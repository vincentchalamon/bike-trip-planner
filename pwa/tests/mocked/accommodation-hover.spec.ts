import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Accommodation hover — map markers", () => {
  test("setting hoveredAccommodation in the store highlights the map marker", async ({
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

    // Wait for map markers to be created
    await expect(mockedPage.locator(".map-marker--acc")).toHaveCount(2);

    // No highlighted marker initially
    await expect(
      mockedPage.locator(".map-marker--acc-highlighted"),
    ).toHaveCount(0);

    // Set hover state via the Zustand store action (not raw setState)
    await mockedPage.evaluate(() => {
      const store = (
        window as Window & {
          __zustand_ui_store?: {
            getState: () => {
              setHoveredAccommodation: (
                v: { stageIndex: number; accIndex: number } | null,
              ) => void;
            };
          };
        }
      ).__zustand_ui_store;
      store?.getState().setHoveredAccommodation({ stageIndex: 0, accIndex: 1 });
    });

    // The corresponding map marker should be highlighted
    await expect(
      mockedPage.locator(".map-marker--acc-highlighted"),
    ).toHaveCount(1);

    // Clear hover state
    await mockedPage.evaluate(() => {
      const store = (
        window as Window & {
          __zustand_ui_store?: {
            getState: () => {
              setHoveredAccommodation: (
                v: { stageIndex: number; accIndex: number } | null,
              ) => void;
            };
          };
        }
      ).__zustand_ui_store;
      store?.getState().setHoveredAccommodation(null);
    });

    // Highlight should be removed
    await expect(
      mockedPage.locator(".map-marker--acc-highlighted"),
    ).toHaveCount(0);
  });

  test("hovering an accommodation item in the timeline highlights its map marker", async ({
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

    // Wait for map markers to be created
    await expect(mockedPage.locator(".map-marker--acc")).toHaveCount(2);

    // Hover the first accommodation item in the timeline (Hotel du Pont — sorted first by distance)
    const stageCard = mockedPage.getByTestId("stage-card-1");
    const accItem = stageCard.getByTestId("accommodation-item").first();
    await accItem.hover();

    // The corresponding map marker should be highlighted
    await expect(
      mockedPage.locator(".map-marker--acc-highlighted"),
    ).toHaveCount(1);

    // Move mouse away → highlight removed
    await mockedPage.mouse.move(0, 0);
    await expect(
      mockedPage.locator(".map-marker--acc-highlighted"),
    ).toHaveCount(0);
  });

  test("selecting an accommodation removes other markers for that stage", async ({
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

    // Two accommodation markers should be visible (Hotel du Pont + Camping Les Oliviers)
    await expect(mockedPage.locator(".map-marker--acc")).toHaveCount(2);

    // Select the first accommodation
    const selectButtons = stageCard.getByRole("button", {
      name: "Sélectionner cet hébergement",
    });
    await selectButtons.first().click();

    // Only the selected marker should remain (with selected class)
    await expect(mockedPage.locator(".map-marker--acc")).toHaveCount(1);
    await expect(mockedPage.locator(".map-marker--acc-selected")).toHaveCount(
      1,
    );
  });

  test("accommodation markers use correct category classes", async ({
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

    // Wait for markers
    await expect(mockedPage.locator(".map-marker--acc")).toHaveCount(2);

    // Hotel du Pont → building, Camping Les Oliviers → camping
    await expect(mockedPage.locator(".map-marker--acc-building")).toHaveCount(
      1,
    );
    await expect(mockedPage.locator(".map-marker--acc-camping")).toHaveCount(1);
  });
});
