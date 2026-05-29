"use client";

import { useEffect, useState } from "react";

/** localStorage key holding the cookie-consent decision (`{ analytics: boolean }`). */
export const CONSENT_STORAGE_KEY = "cookie-consent";

/** Custom event dispatched when the cookie-consent value changes (same tab). */
export const CONSENT_CHANGE_EVENT = "cookie-consent-change";

type CookieConsent = {
  analytics?: boolean;
};

function readAnalyticsConsent(): boolean {
  try {
    const raw = localStorage.getItem(CONSENT_STORAGE_KEY);
    if (!raw) return false;
    const parsed = JSON.parse(raw) as CookieConsent;
    return parsed.analytics === true;
  } catch {
    // Malformed JSON or unavailable localStorage: treat as no consent.
    return false;
  }
}

/**
 * Reads the analytics consent stored under the `cookie-consent` localStorage
 * key (shape: `{ analytics: boolean }`). Defaults to `false` until consent is
 * explicitly granted.
 *
 * Returns `false` during SSR/hydration to avoid hydration mismatches, then
 * resolves to the stored value on the client. Re-reads on storage events and
 * on the `CONSENT_CHANGE_EVENT` so the cookie banner (#385) can grant consent
 * at runtime without a reload.
 */
export function useAnalyticsConsent(): boolean {
  const [consent, setConsent] = useState(false);

  useEffect(() => {
    const update = () => setConsent(readAnalyticsConsent());
    update();

    window.addEventListener("storage", update);
    window.addEventListener(CONSENT_CHANGE_EVENT, update);
    return () => {
      window.removeEventListener("storage", update);
      window.removeEventListener(CONSENT_CHANGE_EVENT, update);
    };
  }, []);

  return consent;
}
