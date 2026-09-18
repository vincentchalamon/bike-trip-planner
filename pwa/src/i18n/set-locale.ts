import { LOCALE_COOKIE, type SupportedLocale } from "./locale";

/** One year, matching the server-side helper so a login does not shorten it. */
export const LOCALE_MAX_AGE_SECONDS = 60 * 60 * 24 * 365;

/**
 * Persist the user's locale preference as a client-side cookie.
 * next-intl reads it from the request on subsequent navigations.
 *
 * Deliberately not httpOnly: the switcher rewrites it from the browser.
 */
export function setLocale(locale: SupportedLocale): void {
  document.cookie = `${LOCALE_COOKIE}=${locale}; path=/; max-age=${LOCALE_MAX_AGE_SECONDS}; SameSite=Lax; Secure`;
}
