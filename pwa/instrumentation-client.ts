/**
 * Next.js 16 client-side instrumentation entrypoint (P1.1).
 *
 * Re-exports the Sentry client configuration so it runs as early as possible
 * in the browser lifecycle (before app code). Required by `@sentry/nextjs`
 * v8+.
 */
import "./sentry.client.config";

export { captureRouterTransitionStart as onRouterTransitionStart } from "@sentry/nextjs";
