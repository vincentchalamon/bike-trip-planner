import { test, expect, type APIRequestContext } from "@playwright/test";
import { expandGpxCard } from "../fixtures/base.fixture";
import path from "node:path";

/**
 * End-to-end smoke test against the REAL backend (no `page.route()` mocking).
 *
 * Golden path:
 *   1. Magic-link login through the real UI: /login -> POST /auth/request-link
 *      -> the backend mails a verify link to Mailcatcher -> read the token from
 *      Mailcatcher's HTTP API -> /auth/verify/{token} sets the BFF session.
 *   2. Import a route via GPX upload (structural pacing runs synchronously,
 *      ADR-043 — no Valhalla / reference data / outbound network required).
 *   3. The trip reaches READY with >= 2 structural stages, rendered as stage cards.
 *
 * CI wiring (see `.github/workflows/ci.yml` `playwright` job): a Mailcatcher
 * service is added via `compose.mailcatcher.yaml`, `MAILER_DSN` points at it,
 * and the `smoke@example.com` user is seeded with `app:create-user` before the
 * suite runs. Mirrors `scripts/recette-seed.sh`, which drives the same flow.
 *
 * Terminal enrichment (weather / accommodations / `trip_ready`) is intentionally
 * NOT asserted: it needs provisioned reference data, the Valhalla routing graph
 * and outbound API access, none of which the lightweight CI stack provides. The
 * structural `stages_computed` result (stage cards) is the completion signal.
 */

const SMOKE_EMAIL = process.env.SMOKE_EMAIL ?? "smoke@example.com";
const MAILCATCHER_URL = process.env.MAILCATCHER_URL ?? "http://localhost:1080";
const GPX_FIXTURE = path.resolve(__dirname, "../fixtures/smoke-route.gpx");

/**
 * Pull the newest `/auth/verify/{token}` token addressed to `email` from
 * Mailcatcher, or "" if none is available yet (so `expect.poll` can retry).
 */
async function readMagicLinkToken(
  request: APIRequestContext,
  email: string,
): Promise<string> {
  const list = await request.get(`${MAILCATCHER_URL}/messages`);
  if (!list.ok()) return "";

  const messages = (await list.json()) as Array<{
    id: number;
    recipients?: string[];
  }>;
  const mine = messages.filter((m) =>
    (m.recipients ?? []).some((r) => r.includes(email)),
  );
  const latest = mine.at(-1);
  if (!latest) return "";

  const body = await request.get(
    `${MAILCATCHER_URL}/messages/${latest.id}.html`,
  );
  if (!body.ok()) return "";

  const html = await body.text();
  return html.match(/auth\/verify\/([A-Za-z0-9_-]+)/)?.[1] ?? "";
}

test.describe("Integration smoke test", () => {
  // Real backend + Mailcatcher only. Magic-link requests are rate limited to
  // 3 per email / 15 min, so run the flow once (Chromium) rather than once per
  // browser project.
  test.skip(
    ({ browserName }) => browserName !== "chromium",
    "Runs once on Chromium against the real backend (magic-link rate limit).",
  );

  test("golden path: magic-link login, GPX import, structural stages", async ({
    page,
    request,
  }) => {
    test.slow();

    // 1. Request a magic link through the real login UI (BFF -> backend -> mail).
    await page.goto("/login");
    await page.locator('input[type="email"]').fill(SMOKE_EMAIL);
    await page
      .getByRole("button", { name: "Recevoir un lien de connexion" })
      .click();
    await expect(page.getByTestId("magic-link-sent")).toBeVisible({
      timeout: 15000,
    });

    // 2. Read the verify token from Mailcatcher (async SMTP delivery).
    let token = "";
    await expect
      .poll(
        async () => {
          token = await readMagicLinkToken(request, SMOKE_EMAIL);
          return token;
        },
        {
          message: "no /auth/verify token captured in Mailcatcher",
          timeout: 30000,
          intervals: [1000, 2000, 2000, 3000],
        },
      )
      .not.toBe("");

    // 3. Consume the token -> BFF stores the session cookie and redirects home.
    await page.goto(`/auth/verify/${token}`);
    await page.waitForURL("/", { timeout: 15000 });

    // 4. Import a route via GPX upload (synchronous structural pacing).
    await expandGpxCard(page);
    await page.getByTestId("gpx-file-input").setInputFiles(GPX_FIXTURE);
    await page.waitForURL(/\/trips\/(?!new\b)/, { timeout: 30000 });

    // 5. The real backend computes >= 2 stages (~110 km route); the trip view
    //    renders them from the detail fetch + `stages_computed` Mercure event.
    await expect(page.getByTestId("stage-card-1")).toBeVisible({
      timeout: 30000,
    });
    await expect(page.getByTestId("stage-card-2")).toBeVisible({
      timeout: 30000,
    });
    await expect(page.getByTestId("total-distance")).toBeVisible();
  });
});
