"use client";

import { useSyncExternalStore, useTransition } from "react";
import { useLocale, useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import { useTheme } from "next-themes";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { SUPPORTED_LOCALES, type SupportedLocale } from "@/i18n/locale";
import { setLocale } from "@/i18n/set-locale";

const LOCALE_LABELS: Record<SupportedLocale, string> = {
  fr: "Français",
  en: "English",
};

const THEME_OPTIONS = ["light", "dark", "system"] as const;
type ThemeOption = (typeof THEME_OPTIONS)[number];

const emptySubscribe = () => () => {};
const getTrue = () => true;
const getFalse = () => false;

/**
 * "Préférences" section: language (synced with the top-bar locale switcher via
 * the `locale` cookie + next-intl) and theme (Light/Dark/Auto synced with
 * next-themes).
 */
export function PreferencesSection() {
  const t = useTranslations("accountSettings.preferences");
  const currentLocale = useLocale();
  const router = useRouter();
  const [isPending, startTransition] = useTransition();
  const { theme, setTheme } = useTheme();
  const mounted = useSyncExternalStore(emptySubscribe, getTrue, getFalse);

  function handleLocaleChange(locale: SupportedLocale) {
    if (locale === currentLocale) return;
    startTransition(() => {
      setLocale(locale);
      router.refresh();
    });
  }

  const themeLabels: Record<ThemeOption, string> = {
    light: t("themeLight"),
    dark: t("themeDark"),
    system: t("themeSystem"),
  };

  return (
    <Card data-testid="preferences-section">
      <CardHeader>
        <CardTitle>{t("title")}</CardTitle>
        <CardDescription>{t("description")}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-6">
        {/* Language */}
        <div
          className="flex flex-col gap-2"
          role="group"
          aria-label={t("languageLabel")}
        >
          <span className="text-sm font-medium">{t("languageLabel")}</span>
          <div className="flex gap-2">
            {SUPPORTED_LOCALES.map((locale) => {
              const isActive = locale === currentLocale;
              return (
                <Button
                  key={locale}
                  variant={isActive ? "default" : "outline"}
                  size="sm"
                  className={cn(
                    "cursor-pointer",
                    isActive && "pointer-events-none",
                  )}
                  disabled={isPending}
                  onClick={() => handleLocaleChange(locale)}
                  aria-pressed={isActive}
                  data-testid={`settings-locale-${locale}`}
                >
                  {LOCALE_LABELS[locale]}
                </Button>
              );
            })}
          </div>
        </div>

        {/* Theme */}
        <div
          className="flex flex-col gap-2"
          role="group"
          aria-label={t("themeLabel")}
        >
          <span className="text-sm font-medium">{t("themeLabel")}</span>
          <div className="flex gap-2">
            {THEME_OPTIONS.map((option) => {
              const isActive = mounted && theme === option;
              return (
                <Button
                  key={option}
                  variant={isActive ? "default" : "outline"}
                  size="sm"
                  className={cn(
                    "cursor-pointer",
                    isActive && "pointer-events-none",
                  )}
                  disabled={!mounted}
                  onClick={() => setTheme(option)}
                  aria-pressed={isActive}
                  data-testid={`settings-theme-${option}`}
                >
                  {themeLabels[option]}
                </Button>
              );
            })}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
