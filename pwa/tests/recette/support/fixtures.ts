import { test as base, createBdd } from "playwright-bdd";
import type { Page } from "@playwright/test";
import { mockAllApis } from "../../fixtures/api-mocks";
import { injectSseEvent, injectSseSequence } from "../../fixtures/sse-helpers";
import { fullTripEventSequence } from "../../fixtures/mock-data";
import type { MercureEvent } from "../../../src/lib/mercure/types";
import {
  clearCurrentRecettePage,
  setCurrentRecettePage,
} from "./current-recette-page";
import { resetAccommodationScanRequest } from "./accommodation-scan-tracker";
import { resetExportDownloadTracker } from "./export-download-tracker";

interface RecetteFixtures {
  mockedPage: Page;
  injectEvent: (event: MercureEvent) => Promise<void>;
  injectSequence: (events: MercureEvent[], delayMs?: number) => Promise<void>;
  submitUrl: (url?: string) => Promise<void>;
  createFullTrip: () => Promise<void>;
}

export const test = base.extend<RecetteFixtures>({
  mockedPage: async ({ page }, use, testInfo) => {
    resetExportDownloadTracker();
    clearCurrentRecettePage();
    resetAccommodationScanRequest();
    const locale = testInfo.file.includes(".en.feature.") ? "en" : "fr";
    await page.context().addCookies([
      {
        name: "locale",
        value: locale,
        url: "https://localhost",
      },
    ]);
    await mockAllApis(page);
    setCurrentRecettePage(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    // Auto-expand the Link card on the welcome screen — mirrors the mocked
    // base fixture so existing steps targeting `magic-link-input` work.
    const linkCard = page.getByTestId("card-link");
    if (await linkCard.isVisible().catch(() => false)) {
      const expanded = await linkCard.getAttribute("data-expanded");
      if (expanded !== "true") await linkCard.click();
    }
    await use(page);
    clearCurrentRecettePage();
    resetAccommodationScanRequest();
  },

  injectEvent: async ({ mockedPage }, use) => {
    await use((event: MercureEvent) => injectSseEvent(mockedPage, event));
  },

  injectSequence: async ({ mockedPage }, use) => {
    await use((events: MercureEvent[], delayMs?: number) =>
      injectSseSequence(mockedPage, events, delayMs),
    );
  },

  submitUrl: async ({ mockedPage }, use) => {
    const { expect } = await import("@playwright/test");
    await use(async (url?: string) => {
      const input = mockedPage.getByTestId("magic-link-input");
      if (!(await input.isVisible().catch(() => false))) {
        await mockedPage.goto("/");
        await mockedPage.waitForLoadState("networkidle");
        const linkCard = mockedPage.getByTestId("card-link");
        if (await linkCard.isVisible().catch(() => false)) {
          const expanded = await linkCard.getAttribute("data-expanded");
          if (expanded !== "true") await linkCard.click();
        }
      }
      await input.fill(url ?? "https://www.komoot.com/fr-fr/tour/2795080048");
      await input.press("Enter");
      await mockedPage.waitForURL(/\/trips\//, { timeout: 10000 });
      await expect(
        mockedPage
          .getByTestId("trip-title-skeleton")
          .or(mockedPage.getByTestId("trip-title")),
      ).toBeVisible({ timeout: 5000 });
    });
  },

  createFullTrip: async ({ submitUrl, injectSequence, mockedPage }, use) => {
    const { expect } = await import("@playwright/test");
    await use(async () => {
      await submitUrl();
      await injectSequence(fullTripEventSequence());
      await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible({
        timeout: 10000,
      });
    });
  },
});

export const { Given, When, Then } = createBdd(test);
