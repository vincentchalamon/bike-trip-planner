"use client";

import { useTranslations } from "next-intl";
import { Eye } from "lucide-react";

/**
 * Permanent read-only banner displayed on the shared trip view (`/s/[code]`).
 *
 * Sits directly under the {@link SharedTopBar} to make the read-only nature of
 * the page unambiguous, distinguishing it from the owner roadbook
 * (`/trips/[id]`) that uses the same master/detail layout.
 *
 * Uses sprint 25 design tokens: rounded-lg, border, blue-toned semantic
 * surface for an informational (not warning) tone.
 */
export function SharedViewBanner() {
  const t = useTranslations("sharePage");

  return (
    <div
      role="status"
      aria-live="polite"
      data-testid="read-only-banner"
      className="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-medium text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300"
    >
      <Eye className="h-4 w-4 shrink-0" aria-hidden="true" />
      <span>{t("readOnlyBanner")}</span>
    </div>
  );
}
