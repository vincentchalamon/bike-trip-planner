"use client";

import { useLocale, useTranslations } from "next-intl";
import { Languages } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { SUPPORTED_LOCALES, type SupportedLocale } from "@/i18n/locale";
import { useSwitchLocale } from "@/components/client-intl-provider";

const LOCALE_LABELS: Record<SupportedLocale, string> = {
  fr: "Français",
  en: "English",
};

export function LocaleSwitcher() {
  const t = useTranslations("config");
  const currentLocale = useLocale();
  const switchLocale = useSwitchLocale();

  function handleLocaleChange(locale: SupportedLocale) {
    if (locale === currentLocale) return;
    switchLocale(locale);
  }

  return (
    <div
      className="flex items-center gap-2"
      role="group"
      aria-label={t("languageTitle")}
    >
      <Languages className="h-4 w-4 text-muted-foreground shrink-0" />
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
              onClick={() => handleLocaleChange(locale)}
              aria-label={t("switchLanguage", {
                language: LOCALE_LABELS[locale],
              })}
              aria-pressed={isActive}
              data-testid={`locale-switch-${locale}`}
            >
              {LOCALE_LABELS[locale]}
            </Button>
          );
        })}
      </div>
    </div>
  );
}
