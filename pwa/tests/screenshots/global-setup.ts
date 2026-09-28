import { request, type FullConfig } from "@playwright/test";

/**
 * The dev server compiles a route on its first request. When that happens while
 * a browser is already on the page, webpack's Fast Refresh does a full reload:
 * the pending navigation to /trips/[id] is lost (or a rebuilt layout chunk
 * fails to load), and the capture fails on a cold start however long it waits.
 * Requesting both routes before any browser opens moves the compilation here.
 * `Accept: text/html` is what makes Caddy route /trips/* to the PWA, not the API.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
  const { baseURL, ignoreHTTPSErrors } = config.projects[0]!.use;
  const context = await request.newContext({
    baseURL,
    ignoreHTTPSErrors,
    extraHTTPHeaders: { Accept: "text/html" },
  });
  for (const route of ["/", "/trips/warmup"]) {
    await context.get(route, { timeout: 120_000 });
  }
  await context.dispose();
}
