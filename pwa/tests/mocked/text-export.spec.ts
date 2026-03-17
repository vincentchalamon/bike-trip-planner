import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Text export", () => {
  test("export button appears after trip is complete", async ({
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
    await expect(mockedPage.getByTestId("text-export-button")).toBeVisible();
  });

  test("copy button shows copied feedback", async ({
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
    await mockedPage.getByTestId("text-export-button").click();
    await mockedPage.getByTestId("text-export-copy-button").click();
    await expect(mockedPage.getByTestId("text-export-copy-button")).toContainText(
      /Copié/,
    );
  });
});
