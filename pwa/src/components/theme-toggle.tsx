"use client";

import { useSyncExternalStore } from "react";
import { useTheme } from "next-themes";
import { useTranslations } from "next-intl";
import { Sun, Moon } from "lucide-react";
import { Button } from "@/components/ui/button";

const emptySubscribe = () => () => {};
const getTrue = () => true;
const getFalse = () => false;

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

  const isDark = resolvedTheme === "dark";
  const nextTheme = isDark ? "light" : "dark";
  const nextLabel = t(isDark ? "light" : "dark");
  const Icon = isDark ? Sun : Moon;

  return (
    <Button
      variant="ghost"
      size="icon"
      className="h-9 w-9 cursor-pointer"
      onClick={() => setTheme(nextTheme)}
      title={t("switchTo", { label: nextLabel })}
      aria-label={t("switchTo", { label: nextLabel })}
    >
      <Icon className="h-4 w-4" />
    </Button>
  );
}
