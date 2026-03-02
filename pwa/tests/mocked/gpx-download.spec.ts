import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  stageGpxReadyEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("GPX download", () => {
  test("GPX button disabled before stage_gpx_ready", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    const gpxButton = stageCard.getByRole("button", {
      name: /Télécharger le GPX/,
    });
    await expect(gpxButton).toBeDisabled();
  });

  test("GPX button enabled after stage_gpx_ready", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      stageGpxReadyEvent(0),
      tripCompleteEvent(),
    ]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    const gpxButton = stageCard.getByRole("button", {
      name: /Télécharger le GPX/,
    });
    await expect(gpxButton).toBeEnabled();
  });
});
