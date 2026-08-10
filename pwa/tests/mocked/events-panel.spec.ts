import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";
import type { MercureEvent } from "../../src/lib/mercure/types";

function eventsFoundEvent(stageIndex: number): MercureEvent {
  return {
    type: "events_found",
    data: {
      stageIndex,
      events: [
        {
          name: "Festival de Jazz de Vals",
          type: "schema:Festival",
          lat: 44.53,
          lon: 4.37,
          startDate: "2025-07-10T00:00:00+02:00",
          endDate: "2025-07-14T00:00:00+02:00",
          url: "https://festival-jazz.example.com",
          description: "Grand festival annuel de jazz en plein air",
          priceMin: 15,
          distanceToEndPoint: 2500,
          source: "datatourisme",
          wikidataId: null,
        },
        {
          name: "Exposition Renoir",
          type: "schema:Exhibition",
          lat: 44.54,
          lon: 4.39,
          startDate: "2025-07-01T00:00:00+02:00",
          endDate: "2025-08-31T00:00:00+02:00",
          url: null,
          description: null,
          priceMin: null,
          distanceToEndPoint: 5000,
          source: "datatourisme",
          wikidataId: "Q12345",
        },
      ],
    },
  };
}

function manyEventsFoundEvent(stageIndex: number, count: number): MercureEvent {
  return {
    type: "events_found",
    data: {
      stageIndex,
      events: Array.from({ length: count }, (_, i) => ({
        name: `Événement ${i + 1}`,
        type: "schema:Festival",
        lat: 44.5,
        lon: 4.3,
        startDate: `2025-07-${String(i + 1).padStart(2, "0")}T00:00:00+02:00`,
        endDate: `2025-07-${String(i + 1).padStart(2, "0")}T00:00:00+02:00`,
        url: `https://example.com/${i + 1}`,
        description: null,
        priceMin: null,
        distanceToEndPoint: 100 * (i + 1),
        source: "datatourisme",
        wikidataId: null,
      })),
    },
  };
}

test.describe("Events panel", () => {
  test("shows events panel toggle when events are present", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      eventsFoundEvent(0),
      tripCompleteEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("events-panel")).toBeVisible();
    await expect(stageCard.getByTestId("events-panel-toggle")).toBeVisible();
    await expect(stageCard.getByTestId("events-panel-toggle")).toContainText(
      "Événements (2)",
    );
  });

  test("expands and shows event list on toggle click", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      eventsFoundEvent(0),
      tripCompleteEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    const toggle = stageCard.getByTestId("events-panel-toggle");

    await toggle.click();

    const content = stageCard.getByTestId("events-panel-content");
    await expect(content).toBeVisible();
    await expect(content).toContainText("Festival de Jazz de Vals");
    await expect(content).toContainText("Exposition Renoir");
  });

  test("shows event metadata including type and date range", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      eventsFoundEvent(0),
      tripCompleteEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await stageCard.getByTestId("events-panel-toggle").click();

    const content = stageCard.getByTestId("events-panel-content");
    await expect(content).toContainText("Festival");
    await expect(content).toContainText("Exposition");
    await expect(content).toContainText("À partir de 15 €");
  });

  test("shows external link for events with url", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      eventsFoundEvent(0),
      tripCompleteEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await stageCard.getByTestId("events-panel-toggle").click();

    const link = stageCard.getByRole("link", {
      name: "Voir le site de Festival de Jazz de Vals",
    });
    await expect(link).toBeVisible();
    await expect(link).toHaveAttribute(
      "href",
      "https://festival-jazz.example.com",
    );
  });

  test("does not render events panel when no events", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      {
        type: "events_found",
        data: { stageIndex: 0, events: [] },
      },
      tripCompleteEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard.getByTestId("events-panel")).not.toBeAttached();
  });

  test("paginates events with a 'show more' button", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      manyEventsFoundEvent(0, 7),
      tripCompleteEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await stageCard.getByTestId("events-panel-toggle").click();

    const content = stageCard.getByTestId("events-panel-content");
    // Only the first 3 events are shown by default.
    await expect(content.getByText("Événement 1")).toBeVisible();
    await expect(content.getByText("Événement 3")).toBeVisible();
    await expect(content.getByText("Événement 4")).toHaveCount(0);

    const showMore = stageCard.getByTestId("events-panel-show-more");
    await expect(showMore).toContainText("Voir plus (4)");
    await showMore.click();

    // All events revealed; the button is gone.
    await expect(content.getByText("Événement 7")).toBeVisible();
    await expect(showMore).toHaveCount(0);
  });

  test("events are grouped by stage index", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      eventsFoundEvent(0),
      eventsFoundEvent(1),
      tripCompleteEvent(),
    ]);

    const stageCard1 = mockedPage.getByTestId("stage-card-1");
    const stageCard2 = mockedPage.getByTestId("stage-card-2");

    await expect(stageCard1.getByTestId("events-panel")).toBeVisible();
    await expect(stageCard2.getByTestId("events-panel")).toBeVisible();

    // stage 1 has its own events
    await stageCard1.getByTestId("events-panel-toggle").click();
    await expect(stageCard1.getByTestId("events-panel-content")).toContainText(
      "Festival de Jazz de Vals",
    );

    // stage 2 has its own events
    await stageCard2.getByTestId("events-panel-toggle").click();
    await expect(stageCard2.getByTestId("events-panel-content")).toContainText(
      "Festival de Jazz de Vals",
    );
  });
});
