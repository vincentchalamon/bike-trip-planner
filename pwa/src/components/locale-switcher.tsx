"use client";

import { useTransition } from "react";
import { useLocale, useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import { Globe } from "lucide-react";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from "@/components/ui/select";
import { SUPPORTED_LOCALES, type SupportedLocale } from "@/i18n/locale";
import { setLocale } from "@/i18n/set-locale";

const LOCALE_LABELS: Record<SupportedLocale, string> = {
  fr: "Français",
  en: "English",
};

// Compact code shown on the trigger on narrow screens to keep the top bar
// within the viewport. The accessible name keeps the full title (aria-label).
const LOCALE_SHORT_LABELS: Record<SupportedLocale, string> = {
  fr: "FR",
  en: "EN",
};

export function LocaleSwitcher() {
  const t = useTranslations("config");
  const currentLocale = useLocale() as SupportedLocale;
  const router = useRouter();
  const [isPending, startTransition] = useTransition();

  function handleLocaleChange(locale: string) {
    if (locale === currentLocale) return;

    startTransition(() => {
      setLocale(locale as SupportedLocale);
      router.refresh();
    });
  }

  return (
    <Select
      value={currentLocale}
      onValueChange={handleLocaleChange}
      disabled={isPending}
    >
      <SelectTrigger
        size="sm"
        className="gap-1.5"
        aria-label={t("languageTitle")}
        data-testid="locale-switch"
        data-locale={currentLocale}
      >
        <Globe className="h-4 w-4" aria-hidden="true" />
        <span className="sm:hidden">{LOCALE_SHORT_LABELS[currentLocale]}</span>
        <span className="hidden sm:inline">{LOCALE_LABELS[currentLocale]}</span>
      </SelectTrigger>
      <SelectContent align="end">
        {SUPPORTED_LOCALES.map((locale) => (
          <SelectItem
            key={locale}
            value={locale}
            data-testid={`locale-switch-${locale}`}
          >
            {LOCALE_LABELS[locale]}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
