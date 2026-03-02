import { test as base, expect, type Page } from "@playwright/test";
import { mockAllApis, type MockApiOptions } from "./api-mocks";
import { injectSseEvent, injectSseSequence } from "./sse-helpers";
import { fullTripEventSequence } from "./mock-data";
import type { MercureEvent } from "../../src/lib/mercure/types";

export { expect };

interface MockedFixtures {
  mockedPage: Page;
  injectEvent: (event: MercureEvent) => Promise<void>;
  injectSequence: (events: MercureEvent[], delayMs?: number) => Promise<void>;
  submitUrl: (url?: string) => Promise<void>;
  createFullTrip: () => Promise<void>;
}

export const test = base.extend<
  MockedFixtures & { mockOptions: MockApiOptions }
>({
  mockOptions: [{}, { option: true }],

  mockedPage: async ({ page, mockOptions }, use) => {
    await mockAllApis(page, mockOptions);
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    await use(page);
  },

  injectEvent: async ({ mockedPage }, use) => {
    await use((event: MercureEvent) => injectSseEvent(mockedPage, event));
  },

  injectSequence: async ({ mockedPage }, use) => {
    await use((events: MercureEvent[], delayMs?: number) =>
      injectSseSequence(mockedPage, events, delayMs),
    );
  },

  submitUrl: async ({ mockedPage, injectEvent }, use) => {
    await use(async (url?: string) => {
      const input = mockedPage.getByTestId("magic-link-input");
      await input.fill(url ?? "https://www.komoot.com/fr-fr/tour/2795080048");
      await input.press("Enter");
      // Wait for trip to be set (title appears as skeleton or editable)
      await expect(
        mockedPage
          .getByTestId("trip-title-skeleton")
          .or(mockedPage.getByTestId("trip-title")),
      ).toBeVisible({ timeout: 5000 });
    });
  },

  createFullTrip: async ({ submitUrl, injectSequence, mockedPage }, use) => {
    await use(async () => {
      await submitUrl();
      await injectSequence(fullTripEventSequence());
      await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
        timeout: 10000,
      });
    });
  },
});
