"use client";

import { Lock } from "lucide-react";
import { useTranslations } from "next-intl";

export function TripLockedBanner() {
  const t = useTranslations("tripLocked");

  return (
    <div
      role="alert"
      className="flex items-center gap-2 rounded-md bg-amber-50 border border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800/40 dark:text-amber-300 px-4 py-3 text-sm font-medium"
      data-testid="trip-locked-banner"
    >
      <Lock className="h-4 w-4 shrink-0" aria-hidden="true" />
      <span>{t("banner")}</span>
    </div>
  );
}
