"use client";

import { useTransition } from "react";
import { useLocale, useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { SUPPORTED_LOCALES, type SupportedLocale } from "@/i18n/locale";
import { setLocale } from "@/i18n/set-locale";

const LOCALE_LABELS: Record<SupportedLocale, string> = {
  fr: "Français",
  en: "English",
};

// Compact pill labels shown on narrow screens to keep the top bar within the
// viewport. The accessible name keeps the full language name (see aria-label).
const LOCALE_SHORT_LABELS: Record<SupportedLocale, string> = {
  fr: "FR",
  en: "EN",
};

export function LocaleSwitcher() {
  const t = useTranslations("config");
  const currentLocale = useLocale();
  const router = useRouter();
  const [isPending, startTransition] = useTransition();

  function handleLocaleChange(locale: SupportedLocale) {
    if (locale === currentLocale) return;

    startTransition(() => {
      setLocale(locale);
      router.refresh();
    });
  }

  return (
    <div
      className="flex items-center gap-2"
      role="group"
      aria-label={t("languageTitle")}
    >
      <div className="flex gap-1">
        {SUPPORTED_LOCALES.map((locale) => {
          const isActive = locale === currentLocale;
          return (
            <Button
              key={locale}
              variant={isActive ? "default" : "outline"}
              size="sm"
              className={cn(
                "h-7 px-2.5 text-xs cursor-pointer",
                isActive && "pointer-events-none",
              )}
              disabled={isPending}
              onClick={() => handleLocaleChange(locale)}
              aria-label={t("switchLanguage", {
                language: LOCALE_LABELS[locale],
              })}
              aria-pressed={isActive}
              data-testid={`locale-switch-${locale}`}
            >
              <span className="sm:hidden">{LOCALE_SHORT_LABELS[locale]}</span>
              <span className="hidden sm:inline">{LOCALE_LABELS[locale]}</span>
            </Button>
          );
        })}
      </div>
    </div>
  );
}
