/**
 * Sentry / GlitchTip client-side configuration (P1.1).
 *
 * Loaded by `instrumentation-client.ts` (Next.js 16 convention) and by the
 * Sentry webpack plugin for source-map upload. Production-only: when no DSN
 * is set in the environment, `Sentry.init` is a no-op and no events are
 * captured.
 */
import * as Sentry from "@sentry/nextjs";

Sentry.init({
  dsn: process.env.NEXT_PUBLIC_SENTRY_DSN,
  environment: process.env.NEXT_PUBLIC_APP_ENV ?? process.env.NODE_ENV,
  release: process.env.NEXT_PUBLIC_APP_RELEASE,

  // Keep ingestion under GlitchTip's free quota while still surfacing slow
  // requests. No session replay (privacy + bandwidth).
  tracesSampleRate: 0.1,
  replaysSessionSampleRate: 0,
  replaysOnErrorSampleRate: 0,

  // Strict-by-default PII posture (no IP, no cookie, no header content).
  // The backend already strips JWTs at the boundary; we mirror that here.
  sendDefaultPii: false,

  beforeSend(event, hint) {
    // Drop transient network failures while the browser knows it is offline:
    // they are user-environment issues, not application bugs.
    if (
      typeof navigator !== "undefined" &&
      navigator.onLine === false &&
      isLikelyNetworkError(hint?.originalException)
    ) {
      return null;
    }

    // Drop the classic `ChunkLoadError` that fires after a deploy invalidates
    // the previous chunks: the next navigation reloads with the new build.
    const err = hint?.originalException;
    if (err instanceof Error && err.name === "ChunkLoadError") {
      return null;
    }

    // Drop Mercure reconnection noise: the EventSource client retries with
    // exponential backoff (see lib/mercure/client.ts) and recovers on its own.
    if (isMercureReconnectError(event, err)) {
      return null;
    }

    return event;
  },
});

function isLikelyNetworkError(error: unknown): boolean {
  if (!error) return false;
  if (error instanceof TypeError) return true; // fetch network failures land here
  if (error instanceof Error) {
    const msg = error.message.toLowerCase();
    return (
      msg.includes("network") ||
      msg.includes("failed to fetch") ||
      msg.includes("load failed")
    );
  }
  return false;
}

function isMercureReconnectError(
  event: Sentry.ErrorEvent,
  error: unknown,
): boolean {
  if (error instanceof Error && error.name === "MercureReconnectError") {
    return true;
  }
  const message = event.message ?? "";
  if (
    typeof message === "string" &&
    message.toLowerCase().includes("mercure") &&
    message.toLowerCase().includes("reconnect")
  ) {
    return true;
  }
  // EventSource native errors carry no useful message; tag them via the
  // breadcrumb category set by `lib/mercure/client.ts`.
  return false;
}
