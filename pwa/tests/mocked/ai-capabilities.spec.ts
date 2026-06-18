import { test, expect } from "../fixtures/base.fixture";
import { mockAllApis } from "../fixtures/api-mocks";

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
  capability: { enabled: boolean; available: boolean; configured?: boolean },
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

/**
 * Same three states for the Acte 1 AI generation card (card-selection): hidden
 * when AI is off by config, disabled with an inline notice when enabled but the
 * LLM tier is unreachable, active otherwise. Availability is driven via the
 * mocked `/api/health` probe; the build-time "disabled" state via the test hook.
 */
test.describe("AI generation card gating (#304)", () => {
  test("tier reachable: the generation card is active and expands to the chat", async ({
    page,
  }) => {
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const card = page.getByTestId("card-ai");
    await expect(card).toBeVisible();
    await expect(page.getByTestId("ai-unavailable-notice")).toHaveCount(0);

    await card.click();
    await expect(page.getByTestId("ai-chat-card")).toBeVisible();
  });

  test("tier enabled but unreachable: the generation card is disabled with a notice", async ({
    page,
  }) => {
    await mockAllApis(page, { aiAvailable: false });
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const card = page.getByTestId("card-ai");
    await expect(card).toBeVisible();
    await expect(card).toHaveAttribute("data-disabled", "true");
    await expect(page.getByTestId("ai-unavailable-notice")).toBeVisible();

    // Disabled → a forced click must not expand the chat composer.
    await card.click({ force: true });
    await expect(page.getByTestId("ai-chat-card")).toHaveCount(0);
  });

  test("disabled by config: the generation card is hidden", async ({ page }) => {
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // Build-time AI_ENABLED can't be flipped at runtime; drive it via the hook.
    await page.evaluate(() =>
      window.dispatchEvent(
        new CustomEvent("__test_set_ai_capability", {
          detail: { enabled: false, available: false },
        }),
      ),
    );

    await expect(page.getByTestId("card-ai")).toHaveCount(0);
    await expect(page.getByTestId("card-link")).toBeVisible();
  });

  test("no provider configured: the generation card is disabled-but-visible with a configure CTA", async ({
    page,
  }) => {
    await mockAllApis(page, { aiConfigured: false });
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const card = page.getByTestId("card-ai");
    await expect(card).toBeVisible();
    await expect(card).toHaveAttribute("data-disabled", "true");

    // The disabled-but-visible affordance carries an actionable settings CTA.
    const notice = page.getByTestId("ai-not-configured-notice");
    await expect(notice).toBeVisible();
    await expect(page.getByTestId("ai-configure-cta")).toHaveAttribute(
      "href",
      "/account/settings#ai",
    );

    // Disabled → a forced click must not expand the chat composer.
    await card.click({ force: true });
    await expect(page.getByTestId("ai-chat-card")).toHaveCount(0);
  });
});

/**
 * ADR-042 — disabled-but-visible AI surfaces in Acte 3 ("Mon voyage") when the
 * account has no AI provider configured. The capability is driven via the test
 * hook with `configured: false`.
 */
test.describe("AI not-configured gating (ADR-042)", () => {
  // Drive the account GET so the `configured` capability resolves to false from
  // the real `useAiSettings` fetch (no race with the test-hook dispatch).
  test.use({ mockOptions: { aiConfigured: false } });

  test("the chat bubble links to the settings instead of opening the panel", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await setAiCapability(mockedPage, {
      enabled: true,
      available: true,
      configured: false,
    });

    const bubble = mockedPage.getByTestId("ai-bubble");
    await expect(bubble).toBeVisible();
    await expect(bubble).toHaveAttribute("data-not-configured", "");
    await expect(bubble).toHaveAttribute("href", "/account/settings#ai");
    // It must not open the chat panel.
    await expect(mockedPage.getByTestId("ai-chat-panel")).toHaveCount(0);

    // The Acte 3 analysis zone surfaces the configure CTA.
    await expect(
      mockedPage.getByTestId("ai-not-configured-notice"),
    ).toBeVisible();
  });
});
