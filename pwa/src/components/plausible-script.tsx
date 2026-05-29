"use client";

import Script from "next/script";
import { useEffect, useRef } from "react";
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

  // `NEXT_PUBLIC_*` are inlined at build time, so E2E (which runs a production
  // build in CI) cannot exercise the "configured" branch via env vars. Mirror
  // the `__PLAYWRIGHT_*` window-override convention already used by the
  // onboarding tour to let tests supply the config at runtime. The override is
  // only ever consulted as a fallback when the real env vars are unset; in a
  // correctly configured production deployment the env vars are set, so the
  // override branch is never reached.
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

  // `<Script>` injects a real `<script>` into the document head; unmounting it
  // (e.g. when consent is revoked via the cookie banner in #385) leaves both the
  // DOM node and Plausible's runtime state in place, so tracking would continue
  // until the next reload. Tear both down explicitly when consent drops.
  const wasInjected = useRef(false);
  useEffect(() => {
    const shouldInject = Boolean(domain && src && hasConsent);
    if (shouldInject) {
      wasInjected.current = true;
      return;
    }
    if (!wasInjected.current) return;
    wasInjected.current = false;
    document.getElementById("plausible-analytics")?.remove();
    delete (window as Window & { plausible?: unknown }).plausible;
  }, [domain, src, hasConsent]);

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
