"use client";

import { useCallback, useEffect, useState } from "react";
import {
  CONSENT_CHANGE_EVENT,
  CONSENT_STORAGE_KEY,
} from "@/hooks/use-analytics-consent";

/**
 * Persisted consent shape. Technical cookies are always on and are not stored
 * (they have no opt-out), so only the analytics opt-in is persisted. The
 * presence of the key marks the banner as dismissed.
 */
export type CookieConsent = {
  analytics: boolean;
};

/**
 * `null` = no decision recorded yet (banner must be shown).
 */
type StoredConsent = CookieConsent | null;

function readConsent(): StoredConsent {
  try {
    const raw = localStorage.getItem(CONSENT_STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<CookieConsent>;
    return { analytics: parsed.analytics === true };
  } catch {
    return null;
  }
}

function writeConsent(consent: CookieConsent): void {
  try {
    localStorage.setItem(CONSENT_STORAGE_KEY, JSON.stringify(consent));
  } catch {
    // localStorage may be unavailable (private browsing); ignore write errors.
  }
  // Notify same-tab listeners (e.g. PlausibleScript) so analytics can load
  // without a reload. The native `storage` event only fires in other tabs.
  window.dispatchEvent(new Event(CONSENT_CHANGE_EVENT));
}

/**
 * Manages cookie consent: reading the stored decision, deciding whether the
 * banner should be shown, and persisting choices to the shared `cookie-consent`
 * localStorage key consumed by {@link useAnalyticsConsent}.
 *
 * `consent` is `null` during SSR/hydration and until localStorage is read, then
 * resolves to the stored value (or `null` if no decision was made yet).
 */
export function useCookieConsent() {
  const [consent, setConsent] = useState<StoredConsent>(null);
  const [resolved, setResolved] = useState(false);

  useEffect(() => {
    setConsent(readConsent());
    setResolved(true);

    const update = () => setConsent(readConsent());
    window.addEventListener("storage", update);
    window.addEventListener(CONSENT_CHANGE_EVENT, update);
    return () => {
      window.removeEventListener("storage", update);
      window.removeEventListener(CONSENT_CHANGE_EVENT, update);
    };
  }, []);

  const save = useCallback((analytics: boolean) => {
    const next = { analytics };
    writeConsent(next);
    setConsent(next);
  }, []);

  const acceptAll = useCallback(() => save(true), [save]);
  const rejectAll = useCallback(() => save(false), [save]);

  // Show the banner only once we know there is no recorded decision.
  const shouldShowBanner = resolved && consent === null;

  return {
    consent,
    resolved,
    shouldShowBanner,
    acceptAll,
    rejectAll,
    save,
  };
}
