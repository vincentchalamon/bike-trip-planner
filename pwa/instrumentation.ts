/**
 * Next.js 16 server-side instrumentation entrypoint (P1.1).
 *
 * Imports the per-runtime Sentry configuration only when the matching
 * Next.js runtime is active. The dynamic imports keep the edge bundle free
 * of Node-only modules.
 */
import * as Sentry from "@sentry/nextjs";

export async function register(): Promise<void> {
  if (process.env.NEXT_RUNTIME === "nodejs") {
    await import("./sentry.server.config");
  }
  if (process.env.NEXT_RUNTIME === "edge") {
    await import("./sentry.edge.config");
  }
}

export const onRequestError = Sentry.captureRequestError;
