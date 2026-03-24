"use client";

import { useTranslations } from "next-intl";
import { Clock } from "lucide-react";
import { Button } from "@/components/ui/button";

export function NoDatesBanner({ onOpenConfig }: { onOpenConfig: () => void }) {
  const t = useTranslations("noDates");

  return (
    <div
      className="flex items-center gap-3 rounded-md border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30 px-4 py-2 text-sm text-amber-800 dark:text-amber-300"
      data-testid="no-dates-banner"
    >
      <Clock className="h-4 w-4 shrink-0" />
      <span className="flex-1">{t("banner")}</span>
      <Button
        variant="ghost"
        size="sm"
        className="h-7 text-amber-800 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/50 shrink-0"
        onClick={onOpenConfig}
      >
        {t("cta")} →
      </Button>
    </div>
  );
}
