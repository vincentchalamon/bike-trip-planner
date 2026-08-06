import { test, expect } from "../fixtures/base.fixture";
import { mockAllApis } from "../fixtures/api-mocks";

/**
 * Issue #304 / ADR-042 — explicit gating of the AI features.
 *
 * AI is per-user (no build-time env gate): the controls are always present and
 * driven solely by runtime signals. Since ADR-042 the AI is a bring-your-own-
 * token cloud provider, so there is no self-hosted tier to probe: `available`
 * stays `true` and the real gate is `configured`, read from the account
 * `GET /users/me/ai-settings`. A provider outage surfaces reactively via the
 * 503 the chat endpoint returns. To pin the states deterministically - and
 * prod-safely, since the E2E build hides `window.__zustand_ui_store` - the tests
 * drive the capability via the `__test_set_ai_capability` CustomEvent:
 *  - reachable + configured   → AI features active
 *  - unreachable              → features disabled with an explicit notice
 *  - not configured           → disabled-but-visible with a configure CTA
 */

function setAiCapability(
  page: import("@playwright/test").Page,
  capability: { available: boolean; configured?: boolean },
): Promise<void> {
  return page.evaluate((detail) => {
    window.dispatchEvent(
      new CustomEvent("__test_set_ai_capability", { detail }),
    );
  }, capability);
}

test.describe("AI capabilities gating (#304)", () => {
  test("tier reachable: no unavailable notice shows in the analysis zone", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await setAiCapability(mockedPage, { available: true });

    await expect(mockedPage.getByTestId("ai-unavailable-notice")).toHaveCount(
      0,
    );
  });

  test("tier unreachable: the degraded-mode notice shows in Acte 3", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await setAiCapability(mockedPage, { available: false });

    // Explicit degraded-mode notice in Acte 3 (Mon voyage).
    await expect(mockedPage.getByTestId("ai-unavailable-notice")).toBeVisible();
  });

  test("not configured: the analysis zone surfaces the configure CTA", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await setAiCapability(mockedPage, { available: true, configured: false });

    await expect(
      mockedPage.getByTestId("ai-not-configured-notice"),
    ).toBeVisible();
  });
});

/**
 * Same states for the Acte 1 AI generation card (card-selection): always
 * visible, disabled with an inline notice when the LLM tier is unreachable or no
 * provider is configured, active otherwise. Availability is driven via the
 * mocked `/api/health` probe; `configured` via the account mock.
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

  test("tier unreachable: the generation card is disabled with a notice", async ({
    page,
  }) => {
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const card = page.getByTestId("card-ai");
    await expect(card).toBeVisible();
    // No self-hosted tier to probe post-ADR-042: a provider outage is driven via
    // the test hook (mirrors the reactive 503-classified state), not the health
    // mock.
    await setAiCapability(page, { available: false });

    await expect(card).toHaveAttribute("data-disabled", "true");
    await expect(page.getByTestId("ai-unavailable-notice")).toBeVisible();

    // Disabled → a forced click must not expand the chat composer.
    await card.click({ force: true });
    await expect(page.getByTestId("ai-chat-card")).toHaveCount(0);
  });

  test("always visible: the generation card stays present alongside the link card", async ({
    page,
  }) => {
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // AI is per-user (no env gate): the card is always rendered.
    await expect(page.getByTestId("card-ai")).toBeVisible();
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
 * account has no AI provider configured. The capability is driven via the real
 * `useAiSettings` fetch (mocked account GET) rather than the test hook.
 */
test.describe("AI not-configured gating (ADR-042)", () => {
  // Drive the account GET so the `configured` capability resolves to false from
  // the real `useAiSettings` fetch (no race with the test-hook dispatch).
  test.use({ mockOptions: { aiConfigured: false } });

  test("the Acte 3 analysis zone surfaces the configure CTA", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await setAiCapability(mockedPage, {
      available: true,
      configured: false,
    });

    await expect(
      mockedPage.getByTestId("ai-not-configured-notice"),
    ).toBeVisible();
  });
});
