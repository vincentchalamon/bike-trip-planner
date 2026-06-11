import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("FIT download", () => {
  test("FIT button enabled after stages computed", async ({
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
    const stageCard = mockedPage.getByTestId("stage-card-1");
    const fitButton = stageCard.getByRole("button", {
      name: /Télécharger le FIT/,
    });
    await expect(fitButton).toBeEnabled();
  });

  test("FIT download triggers API fetch", async ({
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

    // Track FIT API requests
    const fitRequests: string[] = [];
    await mockedPage.route("**/trips/*/stages/*/export.fit", (route) => {
      fitRequests.push(route.request().url());
      return route.fulfill({
        status: 200,
        contentType: "application/vnd.ant.fit",
        body: Buffer.from([0x0e, 0x20, 0x14, 0x08]), // minimal FIT header stub
      });
    });

    const stageCard = mockedPage.getByTestId("stage-card-1");
    const fitButton = stageCard.getByRole("button", {
      name: /Télécharger le FIT/,
    });
    await fitButton.click();

    // Wait for the request to be made
    await expect
      .poll(() => fitRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(fitRequests[0]).toContain("/stages/0/export.fit");
  });

  test("global FIT download button is visible after stages computed", async ({
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

    const button = mockedPage.getByRole("button", {
      name: /Télécharger le FIT complet/,
    });
    await expect(button).toBeVisible();
    await expect(button).toBeEnabled();
  });

  test("global FIT download triggers API fetch on the trip endpoint", async ({
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

    // Track whole-trip FIT API requests (not the per-stage `/stages/*/export.fit`).
    const fitRequests: string[] = [];
    await mockedPage.route("**/trips/*.fit", (route) => {
      fitRequests.push(route.request().url());
      return route.fulfill({
        status: 200,
        contentType: "application/vnd.ant.fit",
        body: Buffer.from([0x0e, 0x20, 0x14, 0x08]), // minimal FIT header stub
      });
    });

    const button = mockedPage.getByRole("button", {
      name: /Télécharger le FIT complet/,
    });
    await button.click();

    await expect
      .poll(() => fitRequests.length, { timeout: 5000 })
      .toBeGreaterThan(0);
    expect(fitRequests[0]).toMatch(/\/trips\/[^/]+\.fit$/);
  });
});
