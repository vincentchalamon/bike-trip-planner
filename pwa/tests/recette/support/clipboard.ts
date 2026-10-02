import type { Page } from "@playwright/test";

/**
 * Record what the page writes to the clipboard instead of writing it. Reading
 * the clipboard back needs a permission only Chromium can grant, so the
 * scenarios assert on the recorded write, the same on every engine.
 */
export async function recordClipboardWrites(page: Page): Promise<void> {
  await page.addInitScript(() => {
    navigator.clipboard.writeText = async (text: string) => {
      (window as unknown as { __clipboard?: string }).__clipboard = text;
    };
  });
}

export function recordedClipboard(page: Page): Promise<string | undefined> {
  return page.evaluate(
    () => (window as unknown as { __clipboard?: string }).__clipboard,
  );
}
