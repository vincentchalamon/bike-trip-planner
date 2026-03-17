import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
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
    await mockedPage.context().grantPermissions(["clipboard-read", "clipboard-write"]);
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

  test("last active stage has no accommodation in text preview", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      accommodationsFoundEvent(1),
      accommodationsFoundEvent(2),
      tripCompleteEvent(),
    ]);
    await mockedPage.getByTestId("text-export-button").click();
    // Last stage line should not contain any accommodation name but should show food budget
    const preview = mockedPage.locator('[role="dialog"] div p').last();
    await expect(preview).not.toContainText("Camping");
    await expect(preview).not.toContainText("Hotel");
    await expect(preview).toContainText("24-40€");
  });
});
