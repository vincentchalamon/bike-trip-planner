"use client";

import { useSyncExternalStore } from "react";
import { useTheme } from "next-themes";
import { useTranslations } from "next-intl";
import { Sun, Moon, Monitor } from "lucide-react";
import { Button } from "@/components/ui/button";

const emptySubscribe = () => () => {};
const getTrue = () => true;
const getFalse = () => false;

// Three-state cycle: light → dark → system → light.
type ThemeState = "light" | "dark" | "system";

const THEME_ICONS: Record<ThemeState, typeof Sun> = {
  light: Sun,
  dark: Moon,
  system: Monitor,
};

const NEXT_THEME: Record<ThemeState, ThemeState> = {
  light: "dark",
  dark: "system",
  system: "light",
};

/**
 * Theme toggle with three states (light / dark / auto) backed by next-themes.
 *
 * A single click cycles to the next state. `system` defers to the OS
 * preference via `setTheme("system")`. The icon reflects the *selected*
 * theme (not the resolved one) so the auto state is always discoverable.
 */
export function ThemeToggle() {
  const t = useTranslations("theme");
  const { theme, setTheme } = useTheme();
  const mounted = useSyncExternalStore(emptySubscribe, getTrue, getFalse);

  if (!mounted) {
    return (
      <Button variant="ghost" size="icon" className="h-9 w-9" disabled>
        <Sun className="h-4 w-4" />
      </Button>
    );
  }

  const current: ThemeState =
    theme === "light" || theme === "dark" ? theme : "system";
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
