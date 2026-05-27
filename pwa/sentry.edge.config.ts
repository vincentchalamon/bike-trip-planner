/**
 * Sentry / GlitchTip edge runtime configuration (P1.1).
 *
 * The Next.js middleware and Edge route handlers run in a constrained V8
 * isolate (no Node APIs). We keep the SDK config minimal here.
 */
import * as Sentry from "@sentry/nextjs";

Sentry.init({
  dsn: process.env.SENTRY_DSN,
  environment: process.env.APP_ENV ?? process.env.NODE_ENV,
  release: process.env.APP_RELEASE,
  tracesSampleRate: 0.05,
  sendDefaultPii: false,
});
