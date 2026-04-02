import type { SupportedLocale } from "./locale";

/**
 * Persist the user's locale preference as a client-side cookie.
 * next-intl reads it from the request on subsequent navigations.
 */
export function setLocale(locale: SupportedLocale): void {
  document.cookie = `locale=${locale}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax; Secure`;
}
