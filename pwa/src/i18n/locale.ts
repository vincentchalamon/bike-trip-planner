export const SUPPORTED_LOCALES = ["fr", "en"] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];
export const DEFAULT_LOCALE: SupportedLocale = "fr";

/**
 * Detect the user's preferred locale from the browser.
 * Falls back to DEFAULT_LOCALE when running server-side or when
 * navigator.language doesn't match any supported locale.
 */
export function detectLocale(): SupportedLocale {
  if (typeof window === "undefined") return DEFAULT_LOCALE;

  const browserLang = navigator.language.split("-")[0];

  if (SUPPORTED_LOCALES.includes(browserLang as SupportedLocale)) {
    return browserLang as SupportedLocale;
  }

  return DEFAULT_LOCALE;
}
