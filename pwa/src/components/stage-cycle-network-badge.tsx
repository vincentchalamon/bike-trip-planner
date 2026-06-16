"use client";

import { Bike } from "lucide-react";
import { useTranslations } from "next-intl";

/**
 * Quality badge shown when a stage largely follows a signed cycle route
 * (EuroVelo, voie verte...). Rendered only above a meaningful share so it stays
 * a positive signal rather than noise (ADR-040).
 */
const THRESHOLD = 0.5;

export function StageCycleNetworkBadge({ fraction }: { fraction: number }) {
  const t = useTranslations("cycleNetwork");

  if (fraction < THRESHOLD) {
    return null;
  }

  return (
    <div
      className="inline-flex items-center gap-1.5 rounded-full bg-green-50 border border-green-200 text-green-800 dark:bg-green-900/20 dark:border-green-800/40 dark:text-green-300 px-3 py-1 text-xs font-medium"
      data-testid="cycle-network-badge"
    >
      <Bike className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      <span>{t("badge", { percent: Math.round(fraction * 100) })}</span>
    </div>
  );
}
