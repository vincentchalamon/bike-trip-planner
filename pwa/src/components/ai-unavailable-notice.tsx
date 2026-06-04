"use client";

import { useTranslations } from "next-intl";
import { CloudOff } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Discreet inline notice shown when the AI tier is enabled but unreachable
 * (explicit degraded mode, #304). Surfaces the outage instead of silently
 * dropping the AI-driven feature. Used by the Acte 3 analysis zone and the
 * refinement card; the chat bubble conveys the same state via its disabled
 * affordance + title.
 */
export function AiUnavailableNotice({
  context = "analysis",
  className,
}: {
  context?: "analysis" | "refinement" | "chat";
  className?: string;
}) {
  const t = useTranslations("aiUnavailable");

  return (
    <div
      role="alert"
      data-testid="ai-unavailable-notice"
      className={cn(
        "flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800",
        "dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200",
        className,
      )}
    >
      <CloudOff className="h-4 w-4 shrink-0" aria-hidden="true" />
      <span>{t(context)}</span>
    </div>
  );
}
