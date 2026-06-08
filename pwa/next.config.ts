import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";
import { withSentryConfig } from "@sentry/nextjs";

const isMobile = process.env.BUILD_TARGET === "mobile";

const nextConfig: NextConfig = {
  output: isMobile ? "export" : "standalone",
  // Don't advertise the framework (audit 35.2 SEC-005: drop `x-powered-by`).
  poweredByHeader: false,
  env: {
    NEXT_PUBLIC_IS_MOBILE_BUILD: isMobile ? "1" : "",
  },
  ...(isMobile
    ? {}
    : {
        async rewrites() {
          return [
            {
              source: "/api/:path*",
              destination: `${process.env.API_BACKEND_URL ?? "http://php"}/:path*`,
            },
          ];
        },
      }),
};

const withNextIntl = createNextIntlPlugin("./src/i18n/request.ts");

// `withSentryConfig` is a no-op build wrapper when SENTRY_AUTH_TOKEN is unset,
// so dev / CI never need a Sentry account. In production, it uploads source
// maps to GlitchTip and tags the release with `APP_RELEASE` (commit SHA).
export default withSentryConfig(withNextIntl(nextConfig), {
  // Suppress noisy CLI output during build.
  silent: true,
  // Upload all chunks (including the ones not directly imported by pages) so
  // every stack frame deminifies in GlitchTip.
  widenClientFileUpload: true,
  // Disable Sentry-specific telemetry pinging back home.
  telemetry: false,
  // Disable the SDK's startup logger (we already log structured JSON).
  disableLogger: true,
  // Strip the uploaded `.map` files from the production bundle so the code
  // source never leaks even if someone scrapes /_next/static.
  sourcemaps: {
    deleteSourcemapsAfterUpload: true,
  },
});
