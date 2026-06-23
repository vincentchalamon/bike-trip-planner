import { test, expect } from "../fixtures/base.fixture";
import { mockAllApis, getTripId } from "../fixtures/api-mocks";

/**
 * ADR-045: the AI card is a real multi-turn brief chat. Each user turn POSTs the
 * whole transcript to `POST /trips/ai-chat`; the structured `collected` summary
 * fills the recap and gates the "Lancer le calcul d'itinéraire" button. Clicking
 * launch consolidates a brief and reuses `POST /trips/ai-generate` (the same
 * async preview lifecycle as URL / GPX creation). Capability gating
 * (disabled-but-visible when not configured) is covered by ai-capabilities.spec.ts.
 */

test("brief chat collects parameters, recommends launch, and fires /trips/ai-generate", async ({
  page,
}) => {
  await mockAllApis(page, {
    aiChatTurns: [
      // First turn: no start yet → launch stays disabled.
      {
        reply: "Où veux-tu partir ?",
        readyToGenerate: false,
        collected: { durationDays: 3 },
      },
      // Second turn: a geocodable start + ready → launch enabled & recommended.
      {
        reply: "Parfait, je tiens ton itinéraire.",
        readyToGenerate: true,
        collected: { start: "Lille", durationDays: 3, loop: true },
      },
    ],
  });
  await page.goto("/");
  await page.waitForLoadState("networkidle");

  await page.getByTestId("card-ai").click();

  const textarea = page.getByTestId("ai-chat-textarea");
  const launch = page.getByTestId("ai-chat-launch");

  // Launch is hard-gated off until a geocodable start is collected.
  await expect(launch).toBeDisabled();

  const lastAssistant = page
    .locator('[data-testid="ai-chat-message"][data-role="assistant"]')
    .last();

  await textarea.fill("Une boucle de 3 jours");
  await textarea.press("Enter");
  await expect(lastAssistant).toContainText("Où veux-tu partir ?");
  // readyToGenerate is false here and start is still missing.
  await expect(launch).toBeDisabled();

  await textarea.fill("Au départ de Lille");
  await textarea.press("Enter");
  await expect(lastAssistant).toContainText("je tiens ton itinéraire");

  // Recap reflects the collected parameters.
  await expect(page.getByTestId("ai-chat-recap-start")).toContainText("Lille");
  await expect(page.getByTestId("ai-chat-recap-durationDays")).toContainText(
    "3",
  );

  // Start is known → launch enabled; readyToGenerate → recommended highlight.
  await expect(launch).toBeEnabled();
  await expect(launch).toHaveAttribute("data-recommended", "true");

  const [request] = await Promise.all([
    page.waitForRequest(
      (req) =>
        req.url().includes("/trips/ai-generate") && req.method() === "POST",
    ),
    launch.click(),
  ]);

  // The consolidated brief carries the structured params + the rider's turns.
  const body = JSON.parse(request.postData() ?? "{}") as { brief?: string };
  expect(body.brief).toContain("start: Lille");
  expect(body.brief).toContain("Au départ de Lille");

  // Generation kicked off → the wizard navigates to the trip preview lifecycle.
  await expect(page).toHaveURL(new RegExp(`/trips/${getTripId()}`));
});

test("the launch button stays disabled without a geocodable start", async ({
  page,
}) => {
  await mockAllApis(page, {
    aiChatTurns: [
      {
        // Model says ready, but no `start` → the hard gate keeps launch off.
        reply: "C'est noté.",
        readyToGenerate: true,
        collected: { durationDays: 5, profile: "gravel" },
      },
    ],
  });
  await page.goto("/");
  await page.waitForLoadState("networkidle");

  await page.getByTestId("card-ai").click();
  const textarea = page.getByTestId("ai-chat-textarea");
  await textarea.fill("5 jours en gravel");
  await textarea.press("Enter");

  await expect(page.getByTestId("ai-chat-recap-durationDays")).toContainText(
    "5",
  );
  await expect(page.getByTestId("ai-chat-launch")).toBeDisabled();
});

test("a 422 ai_not_configured surfaces the configure CTA", async ({ page }) => {
  await mockAllApis(page, {
    aiChatTurns: [{ status: 422 }],
  });
  await page.goto("/");
  await page.waitForLoadState("networkidle");

  await page.getByTestId("card-ai").click();
  const textarea = page.getByTestId("ai-chat-textarea");
  await textarea.fill("Boucle au départ de Lille");
  await textarea.press("Enter");

  const cta = page.getByTestId("ai-chat-configure-cta");
  await expect(page.getByTestId("ai-chat-not-configured")).toBeVisible();
  await expect(cta).toHaveAttribute("href", "/account/settings#ai");
});
