import i18n from 'i18next';
import { getLocales } from 'expo-localization';
import { initReactI18next } from 'react-i18next';
import { en } from './resources/en';
import { fr } from './resources/fr';

export const SUPPORTED_LOCALES = ['fr', 'en'] as const;
export type Locale = (typeof SUPPORTED_LOCALES)[number];

// Device language, defaulting to French. Wrapped so the module stays importable
// (e.g. under jest) even when the native localization module is unavailable.
function deviceLocale(): Locale {
  try {
    return getLocales()[0]?.languageCode === 'en' ? 'en' : 'fr';
  } catch {
    return 'fr';
  }
}

if (!i18n.isInitialized) {
  void i18n.use(initReactI18next).init({
    resources: { fr: { translation: fr }, en: { translation: en } },
    lng: deviceLocale(),
    fallbackLng: 'fr',
    supportedLngs: SUPPORTED_LOCALES,
    interpolation: { escapeValue: false },
    returnNull: false,
  });
}

export function isSupportedLocale(value: unknown): value is Locale {
  return typeof value === 'string' && (SUPPORTED_LOCALES as readonly string[]).includes(value);
}

/**
 * Adopt the account's stored locale as the interface language.
 *
 * The server renders a trip's alerts in that locale (ADR-063), so the UI has to
 * follow it rather than the device's. Ignores anything unsupported or already
 * current, so a missing field or an offline session leaves the app as it is.
 */
export function applyAccountLocale(locale: unknown): void {
  if (!isSupportedLocale(locale) || i18n.language === locale) return;
  void i18n.changeLanguage(locale);
}

// Type the translation keys against the French resource (single source of truth).
declare module 'i18next' {
  interface CustomTypeOptions {
    defaultNS: 'translation';
    resources: { translation: typeof fr };
    returnNull: false;
  }
}

export default i18n;
