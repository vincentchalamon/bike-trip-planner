import { test, expect } from "../fixtures/base.fixture";
import { stagesComputedEvent } from "../fixtures/mock-data";

test.describe("Stage progress bar", () => {
  test("is not visible before trip is created", async ({ mockedPage }) => {
    await expect(
      mockedPage.getByTestId("stage-progress-bar"),
    ).not.toBeVisible();
  });

  test("shows one segment per day after stages are computed", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await expect(mockedPage.getByTestId("stage-progress-bar")).toBeVisible();
    // 3 stages → 3 segments (one per unique dayNumber)
    await expect(mockedPage.getByTestId("progress-segment-1")).toBeVisible();
    await expect(mockedPage.getByTestId("progress-segment-2")).toBeVisible();
    await expect(mockedPage.getByTestId("progress-segment-3")).toBeVisible();
  });

  test("segments carry accessible labels with distance", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Day 1 has 72.5 km → rounded to 73 km in label
    const seg1 = mockedPage.getByTestId("progress-segment-1");
    await expect(seg1).toHaveAttribute("aria-label", /Jour 1.*73/);
  });

  test("clicking a segment sets it as active (aria-current)", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const seg2 = mockedPage.getByTestId("progress-segment-2");
    await seg2.click();
    await expect(seg2).toHaveAttribute("aria-current", "true");
  });

  test("clicking a segment does not activate the others", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await mockedPage.getByTestId("progress-segment-2").click();
    await expect(
      mockedPage.getByTestId("progress-segment-1"),
    ).not.toHaveAttribute("aria-current");
    await expect(
      mockedPage.getByTestId("progress-segment-3"),
    ).not.toHaveAttribute("aria-current");
  });

  test("segments are proportional to distance", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    // Use custom stages with very different distances so widths are clearly distinct
    await injectSequence([
      {
        type: "stages_computed",
        data: {
          stages: [
            {
              dayNumber: 1,
              distance: 100,
              elevation: 500,
              elevationLoss: 400,
              startPoint: { lat: 44.0, lon: 4.0, ele: 200 },
              endPoint: { lat: 44.5, lon: 4.5, ele: 300 },
              geometry: [],
              label: null,
            },
            {
              dayNumber: 2,
              distance: 50,
              elevation: 300,
              elevationLoss: 250,
              startPoint: { lat: 44.5, lon: 4.5, ele: 300 },
              endPoint: { lat: 44.8, lon: 4.8, ele: 350 },
              geometry: [],
              label: null,
            },
          ],
        },
      },
    ]);
    await expect(mockedPage.getByTestId("stage-progress-bar")).toBeVisible();

    // Segment 1 should be roughly twice as wide as segment 2
    const seg1Box = await mockedPage
      .getByTestId("progress-segment-1")
      .boundingBox();
    const seg2Box = await mockedPage
      .getByTestId("progress-segment-2")
      .boundingBox();

    expect(seg1Box).not.toBeNull();
    expect(seg2Box).not.toBeNull();

    if (seg1Box && seg2Box) {
      // Allow ±5px tolerance
      expect(seg1Box.width).toBeGreaterThan(seg2Box.width * 1.8);
    }
  });

  test("groups multi-stage days into a single segment", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    // Two stages on day 1, one stage on day 2
    await injectSequence([
      {
        type: "stages_computed",
        data: {
          stages: [
            {
              dayNumber: 1,
              distance: 40,
              elevation: 400,
              elevationLoss: 350,
              startPoint: { lat: 44.0, lon: 4.0, ele: 200 },
              endPoint: { lat: 44.3, lon: 4.3, ele: 250 },
              geometry: [],
              label: null,
            },
            {
              dayNumber: 1,
              distance: 35,
              elevation: 350,
              elevationLoss: 300,
              startPoint: { lat: 44.3, lon: 4.3, ele: 250 },
              endPoint: { lat: 44.5, lon: 4.5, ele: 300 },
              geometry: [],
              label: null,
            },
            {
              dayNumber: 2,
              distance: 60,
              elevation: 600,
              elevationLoss: 500,
              startPoint: { lat: 44.5, lon: 4.5, ele: 300 },
              endPoint: { lat: 44.8, lon: 4.8, ele: 350 },
              geometry: [],
              label: null,
            },
          ],
        },
      },
    ]);
    await expect(mockedPage.getByTestId("stage-progress-bar")).toBeVisible();
    // Only 2 segments (day 1 and day 2), not 3
    await expect(mockedPage.getByTestId("progress-segment-1")).toBeVisible();
    await expect(mockedPage.getByTestId("progress-segment-2")).toBeVisible();
    await expect(
      mockedPage.getByTestId("progress-segment-3"),
    ).not.toBeVisible();
  });

  test("uses stagesComputedEvent fixture for basic render check", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([stagesComputedEvent()]);
    await expect(mockedPage.getByTestId("stage-progress-bar")).toBeVisible();
    // Should render 3 segments for the 3-day fixture
    const segments = mockedPage.locator('[data-testid^="progress-segment-"]');
    await expect(segments).toHaveCount(3);
  });
});
