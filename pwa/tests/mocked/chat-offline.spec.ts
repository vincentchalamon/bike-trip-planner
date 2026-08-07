import { test, expect } from "../fixtures/base.fixture";

/**
 * Issue #465 — Offline in-ride behaviour.
 *
 * When the browser reports `navigator.onLine === false`, the floating in-ride
 * bubble should surface the offline badge and the button must be disabled so
 * the rider cannot fire a request that would never reach the backend.
 *
 * The AI chat bubble was removed in #929; the guided in-ride bubble that
 * replaces it landed in #935. This spec keeps the offline coverage.
 */

test.describe("In-ride offline", () => {
  test("disables the bubble and surfaces the offline badge", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    const bubble = mockedPage.getByTestId("in-ride-bubble");
    await expect(bubble).toBeVisible({ timeout: 10_000 });

    // Flip the browser into offline mode and dispatch the matching event so
    // the `useOnlineStatus` hook re-evaluates.
    await mockedPage.context().setOffline(true);
    await mockedPage.evaluate(() => {
      Object.defineProperty(navigator, "onLine", {
        configurable: true,
        get: () => false,
      });
      window.dispatchEvent(new Event("offline"));
    });

    // The offline badge overlay is now visible on the bubble.
    await expect(mockedPage.getByTestId("chat-offline-badge")).toBeVisible();

    // The bubble is marked disabled so clicking it cannot open the panel.
    await expect(bubble).toHaveAttribute("aria-disabled", "true");
    await expect(bubble).toBeDisabled();

    // Force-click bypasses Playwright's actionability check; the panel must
    // still NOT open because the button is disabled.
    await bubble.click({ force: true });
    await expect(mockedPage.getByTestId("in-ride-panel")).toHaveCount(0);
  });
});
