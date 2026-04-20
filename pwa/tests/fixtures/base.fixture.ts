import { test as base, expect, type Page } from "@playwright/test";
import { mockAllApis, type MockApiOptions } from "./api-mocks";
import { injectSseEvent, injectSseSequence } from "./sse-helpers";
import { fullTripEventSequence } from "./mock-data";
import type { MercureEvent } from "../../src/lib/mercure/types";

export { expect };

/**
 * Expand the Link card on the welcome screen so the `magic-link-input`
 * URL field becomes visible. No-op if the card is already expanded or
 * not present (e.g. after a trip has been loaded).
 */
export async function expandLinkCard(page: Page): Promise<void> {
  const linkCard = page.getByTestId("card-link");
  if (!(await linkCard.isVisible().catch(() => false))) return;
  const expanded = await linkCard.getAttribute("data-expanded");
  if (expanded !== "true") await linkCard.click();
}

/**
 * Expand the GPX card on the welcome screen so the drop zone and
 * file input become visible. No-op if already expanded or absent.
 */
export async function expandGpxCard(page: Page): Promise<void> {
  const gpxCard = page.getByTestId("card-gpx");
  if (!(await gpxCard.isVisible().catch(() => false))) return;
  const expanded = await gpxCard.getAttribute("data-expanded");
  if (expanded !== "true") await gpxCard.click();
}

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
    // Auto-expand the Link card on the welcome screen so tests that assert
    // on the URL input (magic-link-input) or rely on it being immediately
    // available don't need to click the card themselves. Tests verifying
    // the collapsed card-selection state can navigate to / themselves and
    // opt out of this expansion.
    await expandLinkCard(page);
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
      // After magic link submission the app navigates to /trips/{id}; wait for that URL
      // then wait for the trip title (skeleton or editable)
      await mockedPage.waitForURL(/\/trips\//, { timeout: 5000 });
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
