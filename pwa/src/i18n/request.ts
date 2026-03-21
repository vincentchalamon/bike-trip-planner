import { cookies } from "next/headers";
import { getRequestConfig } from "next-intl/server";
import { SUPPORTED_LOCALES, type SupportedLocale } from "@/i18n/locale";

export default getRequestConfig(async () => {
  const store = await cookies();
  const raw = store.get("locale")?.value;
  const locale: SupportedLocale =
    raw && SUPPORTED_LOCALES.includes(raw as SupportedLocale)
      ? (raw as SupportedLocale)
      : "fr";

  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
