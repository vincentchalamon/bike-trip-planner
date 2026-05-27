/**
 * Sentry / GlitchTip server-side (Node runtime) configuration (P1.1).
 */
import * as Sentry from "@sentry/nextjs";

Sentry.init({
  dsn: process.env.SENTRY_DSN,
  environment: process.env.APP_ENV ?? process.env.NODE_ENV,
  release: process.env.APP_RELEASE,
  tracesSampleRate: 0.05,
  sendDefaultPii: false,
});
