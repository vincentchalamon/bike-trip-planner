import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent } from "../fixtures/mock-data";

test.describe("Calendar widget", () => {
  test("shows calendar after trip creation", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await expect(
      mockedPage.getByRole("grid", { name: "Calendrier" }),
    ).toBeVisible();
  });

  test("expand and collapse calendar", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Calendar should be visible
    const calendar = mockedPage.getByRole("grid", { name: "Calendrier" });
    await expect(calendar).toBeVisible();
    // Click expand button
    const expandButton = mockedPage.getByRole("button", {
      name: "Développer le calendrier",
    });
    await expandButton.click();
    // After expand, collapse button should be visible
    await expect(
      mockedPage.getByRole("button", { name: "Réduire le calendrier" }),
    ).toBeVisible();
    // Click collapse
    await mockedPage
      .getByRole("button", { name: "Réduire le calendrier" })
      .click();
    // Expand button should reappear
    await expect(
      mockedPage.getByRole("button", {
        name: "Développer le calendrier",
      }),
    ).toBeVisible();
  });
});
