"use client";

import { useSyncExternalStore } from "react";
import { useTheme } from "next-themes";
import { useTranslations } from "next-intl";
import { Sun, Moon } from "lucide-react";
import { Button } from "@/components/ui/button";

const emptySubscribe = () => () => {};
const getTrue = () => true;
const getFalse = () => false;

// Two-state toggle: light ⇄ dark.
type ThemeState = "light" | "dark";

const THEME_ICONS: Record<ThemeState, typeof Sun> = {
  light: Sun,
  dark: Moon,
};

const NEXT_THEME: Record<ThemeState, ThemeState> = {
  light: "dark",
  dark: "light",
};

/**
 * Theme toggle with two states (light / dark) backed by next-themes.
 *
 * The provider defaults to `system`, so on first load the theme matches the
 * OS preference. A single click then flips to the opposite *resolved* theme
 * and pins it explicitly (no "auto/system" state — #649). The dedicated
 * Account → Préférences picker exposes the same two options.
 */
export function ThemeToggle() {
  const t = useTranslations("theme");
  const { resolvedTheme, setTheme } = useTheme();
  const mounted = useSyncExternalStore(emptySubscribe, getTrue, getFalse);

  if (!mounted) {
    return (
      <Button variant="ghost" size="icon" className="h-9 w-9" disabled>
        <Sun className="h-4 w-4" />
      </Button>
    );
  }

  // Reflect the resolved theme so the icon stays accurate even while the
  // provider is still on "system" (no explicit light/dark chosen yet).
  const current: ThemeState = resolvedTheme === "dark" ? "dark" : "light";
  const nextTheme = NEXT_THEME[current];
  const Icon = THEME_ICONS[current];

  return (
    <Button
      variant="ghost"
      size="icon"
      className="h-9 w-9 cursor-pointer"
      onClick={() => setTheme(nextTheme)}
      title={t("switchTo", { label: t(nextTheme) })}
      aria-label={t("switchTo", { label: t(nextTheme) })}
      data-testid="theme-toggle"
      data-theme-state={current}
    >
      <Icon className="h-4 w-4" />
    </Button>
  );
}
