"use client";

import { useTransition } from "react";
import { useLocale, useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import { Languages } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { SUPPORTED_LOCALES, type SupportedLocale } from "@/i18n/locale";
import { setLocale } from "@/i18n/set-locale";

const LOCALE_LABELS: Record<SupportedLocale, string> = {
  fr: "Français",
  en: "English",
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
      <Languages
        className={cn(
          "h-4 w-4 text-muted-foreground shrink-0",
          isPending && "animate-spin",
        )}
      />
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
              {LOCALE_LABELS[locale]}
            </Button>
          );
        })}
      </div>
    </div>
  );
}
