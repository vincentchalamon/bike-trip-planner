import { getRequestConfig } from "next-intl/server";
import { DEFAULT_LOCALE } from "@/i18n/locale";

/**
 * Server-side request config for next-intl.
 * Uses a static default locale to avoid cookies() — the actual locale
 * detection and switching happens client-side via ClientIntlProvider.
 * This keeps the build compatible with `output: 'export'` (Capacitor).
 */
export default getRequestConfig(async () => {
  const locale = DEFAULT_LOCALE;

  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
