import { readFileSync } from "node:fs";
import path from "node:path";
import { request, type FullConfig } from "@playwright/test";

/**
 * Playwright globalSetup: creates one REAL share through the public API, so the
 * `/s/{code}` specs (and the recette `/s/<code_court>` steps) run against the
 * real backend. The page fetches the share server-side, which `page.route()`
 * cannot intercept.
 *
 * Input: `E2E_JWT`, a session JWT for an existing user. CI mints it with
 * `bin/console lexik:jwt:generate-token <email>` after `app:create-user` (see
 * the `playwright` and `playwright-recette` jobs). Locally, against a running
 * stack (the token lives 15 minutes):
 *
 *   docker compose exec -T php bin/console app:create-user e2e-share@example.com --no-invite
 *   export E2E_JWT=$(docker compose exec -T php bin/console lexik:jwt:generate-token e2e-share@example.com | tr -d '[:space:]')
 *
 * Output: `E2E_SHARE_CODE` and `E2E_SHARE_TITLE`, inherited by the workers.
 * Without `E2E_JWT` nothing is seeded and the share specs skip themselves.
 */
const GPX_FIXTURE = path.resolve(__dirname, "smoke-route.gpx");
const STAGES_TIMEOUT_MS = 60_000;

export default async function globalSetup(config: FullConfig): Promise<void> {
  const token = process.env.E2E_JWT;
  if (!token || process.env.E2E_SHARE_CODE) return;

  const api = await request.newContext({
    baseURL: config.projects[0]?.use.baseURL ?? "https://localhost",
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: {
      Authorization: `Bearer ${token}`,
      Accept: "application/ld+json",
    },
  });

  try {
    const upload = await api.post("/trips/gpx-upload", {
      multipart: {
        gpxFile: {
          name: "smoke-route.gpx",
          mimeType: "application/gpx+xml",
          buffer: readFileSync(GPX_FIXTURE),
        },
      },
    });
    if (!upload.ok()) {
      throw new Error(
        `GPX upload failed: ${upload.status()} ${await upload.text()}`,
      );
    }
    const { id, title } = (await upload.json()) as {
      id: string;
      title?: string;
    };

    const deadline = Date.now() + STAGES_TIMEOUT_MS;
    for (;;) {
      const detail = await api.get(`/trips/${id}/detail`);
      if (detail.ok()) {
        const { stages } = (await detail.json()) as { stages?: unknown[] };
        if ((stages?.length ?? 0) > 0) break;
      }
      if (Date.now() > deadline) {
        throw new Error(
          `trip ${id} has no stages after ${STAGES_TIMEOUT_MS / 1000}s (last /detail: ${detail.status()})`,
        );
      }
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }

    const share = await api.post(`/trips/${id}/share`, {
      headers: { "Content-Type": "application/ld+json" },
      data: {},
    });
    if (!share.ok()) {
      throw new Error(
        `share creation failed: ${share.status()} ${await share.text()}`,
      );
    }
    const { shortCode } = (await share.json()) as { shortCode: string };

    process.env.E2E_SHARE_CODE = shortCode;
    process.env.E2E_SHARE_TITLE = title ?? "";
  } finally {
    await api.dispose();
  }
}

/** The share seeded by {@link globalSetup}, or `null` when none was. */
export function seededShare(): { code: string; title: string } | null {
  const code = process.env.E2E_SHARE_CODE;
  return code ? { code, title: process.env.E2E_SHARE_TITLE ?? "" } : null;
}
