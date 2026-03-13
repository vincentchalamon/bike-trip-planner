import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("DifficultyGauge", () => {
  test("renders difficulty badge with dot and label", async ({
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

    const card = mockedPage.getByTestId("stage-card-1");
    const badge = card.locator('[aria-label*="km"]').first();
    await expect(badge).toBeVisible();
  });

  test("badge has accessible aria-label with distance and elevation", async ({
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

    const card = mockedPage.getByTestId("stage-card-1");
    const badge = card.locator('[aria-label*="km"]').first();
    await expect(badge).toHaveAttribute("aria-label", /km.*D\+/);
  });
});
