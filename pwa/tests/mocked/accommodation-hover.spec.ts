import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Accommodation hover — map markers", () => {
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

  test("accommodation markers use the unified category icon", async ({
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

    // Both accommodations share the unified accommodation category — the
    // accent colour is applied inline via `background-color`.
    const accommodationMarkers = mockedPage.locator(
      '.map-marker--icon[data-category="accommodation"]',
    );
    await expect(accommodationMarkers).toHaveCount(2);

    // Hotel du Pont (building) → violet, Camping Les Oliviers (camping) → emerald.
    // We sample the inline styles to confirm the per-subtype background.
    const colours = await accommodationMarkers.evaluateAll((nodes) =>
      nodes.map((n) => (n as HTMLElement).style.backgroundColor),
    );
    expect(colours).toContain("rgb(124, 58, 237)"); // #7c3aed building
    expect(colours).toContain("rgb(5, 150, 105)"); // #059669 camping
  });
});
