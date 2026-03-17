import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
  supplyTimelineEvent,
} from "../fixtures/mock-data";

test.describe("Supply timeline", () => {
  test("is not visible before supply data arrives", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    await expect(mockedPage.getByTestId("supply-timeline")).not.toBeVisible();
  });

  test("shows supply timeline in stage card after supply_timeline event", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      supplyTimelineEvent(0),
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Supply timeline is visible inside stage card 1
    const stageCard1 = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard1.getByTestId("supply-timeline")).toBeVisible();
  });

  test("shows correct marker emojis for water, food, and both types", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      supplyTimelineEvent(0),
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Water marker at 15 km
    const waterMarker = mockedPage.getByTestId("supply-marker-15");
    await expect(waterMarker).toBeVisible();
    await expect(waterMarker).toContainText("💧");

    // Food marker at 42 km
    const foodMarker = mockedPage.getByTestId("supply-marker-42");
    await expect(foodMarker).toBeVisible();
    await expect(foodMarker).toContainText("🍴");

    // Both marker at 59 km
    const bothMarker = mockedPage.getByTestId("supply-marker-59");
    await expect(bothMarker).toBeVisible();
    await expect(bothMarker).toContainText("🏘️");
  });

  test("clicking a water marker shows tooltip with water section", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      supplyTimelineEvent(0),
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    const waterMarker = mockedPage.getByTestId("supply-marker-15");
    await waterMarker.click();

    // Tooltip appears
    await expect(mockedPage.getByTestId("supply-tooltip")).toBeVisible({
      timeout: 3000,
    });

    // Contains the water point name
    await expect(mockedPage.getByTestId("supply-tooltip")).toContainText(
      "Cimetière de Vals",
    );

    // Distance is shown
    await expect(mockedPage.getByTestId("supply-tooltip")).toContainText(
      "15 km",
    );
  });

  test("clicking a food marker shows tooltip with food section", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      supplyTimelineEvent(0),
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    const foodMarker = mockedPage.getByTestId("supply-marker-42");
    await foodMarker.click();

    await expect(mockedPage.getByTestId("supply-tooltip")).toBeVisible({
      timeout: 3000,
    });

    // Contains food items
    await expect(mockedPage.getByTestId("supply-tooltip")).toContainText(
      "Boulangerie du Village",
    );
    await expect(mockedPage.getByTestId("supply-tooltip")).toContainText(
      "Épicerie Centrale",
    );
  });

  test("clicking a both marker shows tooltip with water and food sections", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      supplyTimelineEvent(0),
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    const bothMarker = mockedPage.getByTestId("supply-marker-59");
    await bothMarker.click();

    await expect(mockedPage.getByTestId("supply-tooltip")).toBeVisible({
      timeout: 3000,
    });

    // Both sections present
    await expect(mockedPage.getByTestId("supply-tooltip")).toContainText(
      "Cimetière de Ruoms",
    );
    await expect(mockedPage.getByTestId("supply-tooltip")).toContainText(
      "Restaurant Les Gorges",
    );
  });

  test("supply timeline is not shown on rest day cards", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      {
        type: "stages_computed",
        data: {
          stages: [
            {
              dayNumber: 1,
              distance: 72.5,
              elevation: 1180,
              elevationLoss: 920,
              startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
              endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
              geometry: [],
              label: null,
              isRestDay: false,
            },
            {
              dayNumber: 2,
              distance: 0,
              elevation: 0,
              elevationLoss: 0,
              startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
              endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
              geometry: [],
              label: null,
              isRestDay: true,
            },
            {
              dayNumber: 3,
              distance: 51.6,
              elevation: 800,
              elevationLoss: 750,
              startPoint: { lat: 44.295, lon: 4.087, ele: 360 },
              endPoint: { lat: 44.112, lon: 3.876, ele: 410 },
              geometry: [],
              label: null,
              isRestDay: false,
            },
          ],
        },
      },
      // supply_timeline event for index 1 (rest day) — should be ignored by UI
      supplyTimelineEvent(1),
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("rest-day-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Rest day card should not contain supply timeline
    const restDayCard = mockedPage.getByTestId("rest-day-card-1");
    await expect(restDayCard.getByTestId("supply-timeline")).not.toBeVisible();
  });

  test("multiple stages each get their own supply timeline", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      supplyTimelineEvent(0),
      supplyTimelineEvent(1),
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
      timeout: 10000,
    });

    // Both stage 1 and stage 2 have supply timelines
    await expect(
      mockedPage.getByTestId("stage-card-1").getByTestId("supply-timeline"),
    ).toBeVisible();
    await expect(
      mockedPage.getByTestId("stage-card-2").getByTestId("supply-timeline"),
    ).toBeVisible();
    // Stage 3 has no supply data
    await expect(
      mockedPage.getByTestId("stage-card-3").getByTestId("supply-timeline"),
    ).not.toBeVisible();
  });
});
