/**
 * The Mercure hub the browser subscribes to.
 *
 * An explicit `NEXT_PUBLIC_MERCURE_URL` wins; otherwise the hub is taken from the CURRENT
 * origin, so the app works unchanged on https://localhost, in prod (same origin), and behind a
 * tunnel (ngrok) — without baking a URL into the bundle. The localhost fallback only applies
 * during SSR, where no EventSource is opened.
 */
export function resolveMercureHubUrl(): string {
  if (process.env.NEXT_PUBLIC_MERCURE_URL) {
    return process.env.NEXT_PUBLIC_MERCURE_URL;
  }

  if (typeof window !== "undefined") {
    return `${window.location.origin}/.well-known/mercure`;
  }

  return "https://localhost/.well-known/mercure";
}
