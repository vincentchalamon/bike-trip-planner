"use client";

import Script from "next/script";
import { useAnalyticsConsent } from "@/hooks/use-analytics-consent";

/**
 * Plausible analytics (self-hosted) loader.
 *
 * Injects the Plausible script only when BOTH conditions hold:
 *  1. `NEXT_PUBLIC_PLAUSIBLE_DOMAIN` and `NEXT_PUBLIC_PLAUSIBLE_SRC` are set;
 *  2. the user has granted analytics consent (see {@link useAnalyticsConsent}).
 *
 * Until consent is granted, nothing is rendered and no request is made to the
 * Plausible domain. The real cookie-banner gating wiring lands in #385; this
 * component already reads the shared consent source.
 */
type PlausibleTestOverride = Window & {
  __PLAYWRIGHT_PLAUSIBLE_DOMAIN?: string;
  __PLAYWRIGHT_PLAUSIBLE_SRC?: string;
};

export function PlausibleScript() {
  const hasConsent = useAnalyticsConsent();

  // `NEXT_PUBLIC_*` are inlined at build time, so E2E running against an
  // env-less dev server cannot exercise the "configured" branch. Mirror the
  // `__PLAYWRIGHT_*` window-override convention already used by the onboarding
  // tour to let tests supply the config at runtime.
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

  if (!domain || !src || !hasConsent) {
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
