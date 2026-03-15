import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Rest day management", () => {
  test("inserts a rest day card between stages", async ({
    createFullTrip,
    injectSequence,
    mockedPage,
  }) => {
    await createFullTrip();

    await mockedPage.route("**/stages/*/rest-day", (route) => {
      return route.fulfill({ status: 202, body: "" });
    });

    await mockedPage.getByTestId("add-rest-day-button-0").click();

    // Optimistic insert: rest-day-card-1 appears immediately
    await expect(mockedPage.getByTestId("rest-day-card-1")).toBeVisible({
      timeout: 5000,
    });

    // Mercure confirms: inject updated stages with rest day at index 1
    await injectSequence([
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
              distance: 63.2,
              elevation: 870,
              elevationLoss: 1050,
              startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
              endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
              geometry: [],
              label: null,
              isRestDay: false,
            },
            {
              dayNumber: 4,
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
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("rest-day-card-1")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("stage-card-2")).toBeHidden();
  });

  test("hides add-rest-day button when adjacent rest day exists", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();

    // Trip with a rest day at index 1 (between stage 0 and stage 2)
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
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("rest-day-card-1")).toBeVisible({
      timeout: 10000,
    });

    // The button after stage 0 (which precedes the rest day) must be hidden
    await expect(
      mockedPage.getByTestId("add-rest-day-button-0"),
    ).toBeHidden();
    // The button after the rest day itself (index 1) must also be hidden
    await expect(
      mockedPage.getByTestId("add-rest-day-button-1"),
    ).toBeHidden();
  });

  test("deletes a rest day without merging adjacent stages", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();

    // Trip with a rest day at index 1
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
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("rest-day-card-1")).toBeVisible({
      timeout: 10000,
    });

    await mockedPage.route("**/trips/*/stages/*", (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      return route.fulfill({ status: 202, body: "" });
    });

    await mockedPage.getByTestId("delete-rest-day-1").click();

    // Rest day card removed, adjacent stages unchanged
    await expect(mockedPage.getByTestId("rest-day-card-1")).toBeHidden({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
  });

  test("inserting a rest day does not trigger the accommodation loading indicator", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    // Trip with stages but no accommodations (geographic scan not run)
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
      timeout: 10000,
    });

    await mockedPage.route("**/stages/*/rest-day", (route) => {
      return route.fulfill({ status: 202, body: "" });
    });

    await mockedPage.getByTestId("add-rest-day-button-0").click();

    // Optimistic insert: rest day card appears immediately
    await expect(mockedPage.getByTestId("rest-day-card-1")).toBeVisible({
      timeout: 5000,
    });

    // The accommodation loading indicator must NOT appear:
    // inserting a rest day sets isProcessing=true but NOT isAccommodationScanning
    await expect(
      mockedPage.getByTestId("accommodation-loading"),
    ).not.toBeVisible();
  });

  test("add-rest-day buttons are not disabled during accommodation scan", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    // Inject stages but NOT trip_complete so isAccommodationScanning remains true
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);

    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    // Buttons must remain enabled even while an accommodation scan is in progress
    await expect(
      mockedPage.getByTestId("add-rest-day-button-0"),
    ).not.toBeDisabled();
  });

  test("rolls back optimistic insert on API failure", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await mockedPage.route("**/stages/*/rest-day", (route) => {
      return route.fulfill({ status: 422, body: "" });
    });

    await mockedPage.getByTestId("add-rest-day-button-0").click();

    // Rest day card should disappear after rollback
    await expect(mockedPage.getByTestId("rest-day-card-1")).toBeHidden({
      timeout: 5000,
    });
    // Original stages remain
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();
  });
});
