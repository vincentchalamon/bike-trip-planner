import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  culturalPoiAlertsEvent,
  routeSegmentRecalculatedEvent,
  terrainAlertsEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Cultural POI suggestions", () => {
  test("shows cultural POI alert on stage card", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      culturalPoiAlertsEvent(),
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("stage-card-1")).toContainText(
      "Château de Ventadour",
    );
  });

  test("shows add-to-itinerary button on cultural POI alert", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      culturalPoiAlertsEvent(),
      tripCompleteEvent(),
    ]);

    const addButton = mockedPage
      .getByTestId("stage-card-1")
      .getByTestId("add-poi-to-itinerary");
    await expect(addButton).toBeVisible();
  });

  test("clicking add-to-itinerary triggers route recalculation and updates stage", async ({
    submitUrl,
    injectSequence,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      culturalPoiAlertsEvent(),
      tripCompleteEvent(),
    ]);

    // Click the add-to-itinerary button
    const addButton = mockedPage
      .getByTestId("stage-card-1")
      .getByTestId("add-poi-to-itinerary");
    await addButton.click();

    // Inject the route_segment_recalculated event (simulating async backend response)
    await injectEvent(routeSegmentRecalculatedEvent(0));

    // Stage 1 distance should be updated (75.2 km from mock event)
    await expect(mockedPage.getByTestId("stage-card-1")).toContainText("75.2");
  });
});

test.describe("Cultural POI — negative cases", () => {
  test("non-cultural alerts do not show add-to-itinerary button", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      terrainAlertsEvent(),
      tripCompleteEvent(),
    ]);

    await expect(
      mockedPage.getByTestId("add-poi-to-itinerary"),
    ).not.toBeVisible();
  });
});
