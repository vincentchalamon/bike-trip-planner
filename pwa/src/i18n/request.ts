import { getRequestConfig } from "next-intl/server";
import {
  DEFAULT_LOCALE,
  SUPPORTED_LOCALES,
  type SupportedLocale,
} from "@/i18n/locale";

export default getRequestConfig(async () => {
  // mobile export: locale is fixed at build time (no request context in static export)
  // TODO: implement proper per-locale builds or [locale] path segments for full i18n support on mobile
  let locale: SupportedLocale = DEFAULT_LOCALE;

  if (process.env.NEXT_PUBLIC_IS_MOBILE_BUILD !== "1") {
    const { cookies } = await import("next/headers");
    const store = await cookies();
    const raw = store.get("locale")?.value;
    locale =
      raw && SUPPORTED_LOCALES.includes(raw as SupportedLocale)
        ? (raw as SupportedLocale)
        : DEFAULT_LOCALE;
  }

  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
