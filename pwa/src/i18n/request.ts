import { getRequestConfig } from "next-intl/server";
import { SUPPORTED_LOCALES, type SupportedLocale } from "@/i18n/locale";

export default getRequestConfig(async () => {
  let locale: SupportedLocale = "fr";

  if (process.env.BUILD_TARGET !== "mobile") {
    const { cookies } = await import("next/headers");
    const store = await cookies();
    const raw = store.get("locale")?.value;
    locale =
      raw && SUPPORTED_LOCALES.includes(raw as SupportedLocale)
        ? (raw as SupportedLocale)
        : "fr";
  }

  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
