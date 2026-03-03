import type { Page } from "@playwright/test";
import type { MercureEvent } from "../../src/lib/mercure/types";

export async function injectSseEvent(
  page: Page,
  event: MercureEvent,
): Promise<void> {
  await page.evaluate((evt) => {
    window.dispatchEvent(
      new CustomEvent("__test_mercure_event", { detail: evt }),
    );
  }, event);
}

export async function injectSseSequence(
  page: Page,
  events: MercureEvent[],
  delayMs = 50,
): Promise<void> {
  for (const event of events) {
    await injectSseEvent(page, event);
    await page.waitForTimeout(delayMs);
  }
}
