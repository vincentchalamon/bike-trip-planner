import { getRequestConfig } from "next-intl/server";
import {
  DEFAULT_LOCALE,
  SUPPORTED_LOCALES,
  type SupportedLocale,
} from "@/i18n/locale";

export default getRequestConfig(async () => {
  const { cookies } = await import("next/headers");
  const store = await cookies();
  const raw = store.get("locale")?.value;
  const locale: SupportedLocale =
    raw && SUPPORTED_LOCALES.includes(raw as SupportedLocale)
      ? (raw as SupportedLocale)
      : DEFAULT_LOCALE;

  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
