import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent, stagesComputedEvent } from "../fixtures/mock-data";

test.describe("Title editing", () => {
  test("shows loader while computing", async ({ submitUrl, mockedPage }) => {
    await submitUrl();
    // Before structural stages exist, the synchronous flow shows the single
    // loader; the editable title only appears once the trip view mounts.
    await expect(mockedPage.getByTestId("trip-loader")).toBeVisible();
  });

  test("shows editable title once stages exist", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
    await expect(mockedPage.getByTestId("trip-title")).toBeVisible();
  });

  test("edits title via click and type", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
    const title = mockedPage.getByTestId("trip-title");
    await expect(title).toBeVisible();
    // Click to enter edit mode
    await title.click();
    // Should now be an input
    const input = mockedPage.getByRole("textbox", {
      name: "Titre du voyage",
    });
    await expect(input).toBeVisible();
    await input.fill("Mon voyage en Ardeche");
    await input.press("Enter");
    // Should show the updated title
    await expect(mockedPage.getByTestId("trip-title")).toContainText(
      "Mon voyage en Ardeche",
    );
  });
});
