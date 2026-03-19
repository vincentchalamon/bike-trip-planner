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

    // Set hover state via the Zustand store (Hotel du Pont = accIndex 1)
    await mockedPage.evaluate(() => {
      (
        window as Window & {
          __zustand_ui_store?: { setState: (s: object) => void };
        }
      ).__zustand_ui_store?.setState({
        hoveredAccommodation: { stageIndex: 0, accIndex: 1 },
      });
    });

    // The corresponding map marker should be highlighted
    await expect(
      mockedPage.locator(".map-marker--acc-highlighted"),
    ).toHaveCount(1);

    // Clear hover state
    await mockedPage.evaluate(() => {
      (
        window as Window & {
          __zustand_ui_store?: { setState: (s: object) => void };
        }
      ).__zustand_ui_store?.setState({
        hoveredAccommodation: null,
      });
    });

    // Highlight should be removed
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
