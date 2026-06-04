import { test, expect } from "../fixtures/base.fixture";

/**
 * Issue #304 — explicit degraded-mode gating of the AI features.
 *
 * `NEXT_PUBLIC_AI_ENABLED` defaults to enabled and runtime availability comes
 * from `/api/health`. To pin all three states deterministically — and prod-safely,
 * since the E2E build hides `window.__zustand_ui_store` — the tests drive the
 * capability via the `__test_set_ai_capability` CustomEvent:
 *  - enabled + reachable   → AI features active
 *  - enabled + unreachable → features disabled with an explicit notice
 *  - disabled by config    → features hidden (no notice)
 */

function setAiCapability(
  page: import("@playwright/test").Page,
  capability: { enabled: boolean; available: boolean },
): Promise<void> {
  return page.evaluate((detail) => {
    window.dispatchEvent(
      new CustomEvent("__test_set_ai_capability", { detail }),
    );
  }, capability);
}

test.describe("AI capabilities gating (#304)", () => {
  test("tier reachable: the assistant is active and no unavailable notice shows", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await setAiCapability(mockedPage, { enabled: true, available: true });

    const bubble = mockedPage.getByTestId("ai-bubble");
    await expect(bubble).toBeVisible();
    await expect(mockedPage.getByTestId("ai-unavailable-notice")).toHaveCount(
      0,
    );

    // Active → clicking opens the chat panel.
    await bubble.click();
    await expect(mockedPage.getByTestId("ai-chat-panel")).toBeVisible();
  });

  test("tier enabled but unreachable: the assistant is disabled and the notice shows", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await setAiCapability(mockedPage, { enabled: true, available: false });

    const bubble = mockedPage.getByTestId("ai-bubble");
    await expect(bubble).toBeVisible();
    await expect(bubble).toHaveAttribute("aria-disabled", "true");
    await expect(bubble).toHaveAttribute("data-ai-down", "true");

    // Disabled → a forced click must not open the panel.
    await bubble.click({ force: true });
    await expect(mockedPage.getByTestId("ai-chat-panel")).toHaveCount(0);

    // Explicit degraded-mode notice in Acte 3 (Mon voyage).
    await expect(mockedPage.getByTestId("ai-unavailable-notice")).toBeVisible();
  });

  test("disabled by config: AI features are hidden with no notice", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await setAiCapability(mockedPage, { enabled: false, available: false });

    await expect(mockedPage.getByTestId("ai-bubble")).toHaveCount(0);
    await expect(mockedPage.getByTestId("ai-unavailable-notice")).toHaveCount(
      0,
    );
  });
});
