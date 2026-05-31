"use client";

import Script from "next/script";

/**
 * Plausible analytics (self-hosted) loader.
 *
 * Injects the Plausible script only when `NEXT_PUBLIC_PLAUSIBLE_DOMAIN` and
 * `NEXT_PUBLIC_PLAUSIBLE_SRC` are set. Plausible is cookieless and stores no
 * personal data, so no consent banner is required (legitimate interest — see
 * `/privacy` and ADR-034); loading is gated solely on the environment being
 * configured. When the vars are unset (e.g. the restricted beta), nothing is
 * rendered and no request is made to the Plausible domain.
 */
type PlausibleTestOverride = Window & {
  __PLAYWRIGHT_PLAUSIBLE_DOMAIN?: string;
  __PLAYWRIGHT_PLAUSIBLE_SRC?: string;
};

export function PlausibleScript() {
  // `NEXT_PUBLIC_*` are inlined at build time, so E2E (which runs a production
  // build in CI) cannot exercise the "configured" branch via env vars. Mirror
  // the `__PLAYWRIGHT_*` window-override convention used by the onboarding tour
  // to let tests supply the config at runtime. The override is only ever
  // consulted as a fallback when the real env vars are unset; in a correctly
  // configured production deployment the env vars are set, so the override
  // branch is never reached.
  const override =
    typeof window !== "undefined"
      ? (window as PlausibleTestOverride)
      : undefined;
  const domain =
    process.env.NEXT_PUBLIC_PLAUSIBLE_DOMAIN ??
    override?.__PLAYWRIGHT_PLAUSIBLE_DOMAIN;
  const src =
    process.env.NEXT_PUBLIC_PLAUSIBLE_SRC ??
    override?.__PLAYWRIGHT_PLAUSIBLE_SRC;

  if (!domain || !src) {
    return null;
  }

  return (
    <Script
      id="plausible-analytics"
      data-testid="plausible-script"
      data-domain={domain}
      src={src}
      strategy="afterInteractive"
    />
  );
}
