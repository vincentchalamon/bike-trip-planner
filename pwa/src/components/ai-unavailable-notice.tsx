"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { CloudOff, Sparkles } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Discreet inline notice shown when an AI surface cannot be used.
 *
 * Two flavours:
 * - `outage` (default): the AI tier is enabled but unreachable (explicit
 *   degraded mode, #304) — surfaces the outage instead of silently dropping
 *   the AI-driven feature.
 * - `notConfigured` (ADR-042): AI is enabled but the account has no provider +
 *   token set — surfaces a "Configurez une IA" CTA linking to the account
 *   settings so the disabled-but-visible control is actionable.
 *
 * Used by the Acte 3 analysis zone, the refinement card and the AI card; the
 * chat bubble conveys the same state via its disabled affordance + title.
 */
export function AiUnavailableNotice({
  context = "analysis",
  variant = "outage",
  className,
}: {
  context?: "analysis" | "refinement" | "chat";
  variant?: "outage" | "notConfigured";
  className?: string;
}) {
  const t = useTranslations("aiUnavailable");

  if (variant === "notConfigured") {
    return (
      <div
        role="alert"
        data-testid="ai-not-configured-notice"
        className={cn(
          "flex flex-col gap-2 rounded-md border border-brand/30 bg-brand/5 px-3 py-2 text-sm text-foreground",
          className,
        )}
      >
        <span className="flex items-center gap-2">
          <Sparkles
            className="h-4 w-4 shrink-0 text-brand"
            aria-hidden="true"
          />
          <span>{t("notConfigured")}</span>
        </span>
        <Link
          href="/account/settings#ai"
          className="self-start text-sm font-medium text-brand hover:underline"
          data-testid="ai-configure-cta"
        >
          {t("configureCta")}
        </Link>
      </div>
    );
  }

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
