import { test, expect } from "../fixtures/base.fixture";
import { mockAllApis, getTripId } from "../fixtures/api-mocks";

/**
 * B2 (ADR-042): the AI card forwards the rider's brief to
 * `POST /trips/ai-generate` and then joins the same async preview lifecycle as
 * URL / GPX creation. Gating (disabled-but-visible when not configured) is
 * covered by ai-capabilities.spec.ts; here we exercise the happy path.
 */
test.beforeEach(async ({ page }) => {
  await mockAllApis(page);
  await page.goto("/");
  await page.waitForLoadState("networkidle");
});

test("AI card submits the brief to /trips/ai-generate and starts generation", async ({
  page,
}) => {
  await page.getByTestId("card-ai").click();

  const textarea = page.getByTestId("ai-chat-textarea");
  await textarea.fill(
    "Boucle au départ de Lille, 2 jours, 80 km par jour, en tente",
  );
  await textarea.press("Enter");

  // The rider's turn is recorded (the assistant reply is a local stub).
  await expect(
    page.locator('[data-testid="ai-chat-message"][data-role="user"]'),
  ).toHaveCount(1);

  const [request] = await Promise.all([
    page.waitForRequest(
      (req) =>
        req.url().includes("/trips/ai-generate") && req.method() === "POST",
    ),
    page.getByTestId("ai-chat-submit").click(),
  ]);

  // Only the user's words are forwarded as the brief — never the stub replies.
  const body = JSON.parse(request.postData() ?? "{}") as { brief?: string };
  expect(body.brief).toContain("Boucle au départ de Lille");
  expect(body.brief).toContain("80 km par jour");

  // Generation kicked off → the wizard navigates to the trip preview lifecycle.
  await expect(page).toHaveURL(new RegExp(`/trips/${getTripId()}`));
});
