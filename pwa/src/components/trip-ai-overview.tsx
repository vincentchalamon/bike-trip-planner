"use client";

import { useId, useState } from "react";
import { useTranslations } from "next-intl";
import { Bot, ChevronDown, ChevronUp, Loader2, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import { useTripAiOverview } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";

/**
 * Trip-level AI overview card (issue #305, ADR-043 per-block async).
 *
 * Renders the narrative + patterns + recommendations + cross-stage alerts
 * produced by the LLaMA pass 2 (`AnalyzeTripOverviewWithLlmHandler` →
 * `aiOverview` field on the `trip_ready` Mercure event).
 *
 * Placement — top of the "Mon voyage" view, above the stage cards.
 *
 * Per-block behaviour (`useUiStore.blockStatus.ai`):
 * - `pending` / `running` → skeleton spinner (the LLM pass is still in flight,
 *   over the already-displayed trip view).
 * - `failed` → error notice + "Régénérer" button (re-runs the analysis).
 * - `done` (or any state) with an overview present → the full card.
 * - otherwise (no overview, idle / TTL-expired) → silent fallback (renders
 *   nothing), preserving the original #305 contract.
 *
 * On desktop (≥ md) the card is always fully expanded; on mobile (< md) only
 * the title + first narrative line show until the user taps the disclosure.
 */
export function TripAiOverview({
  onRegenerate,
}: {
  /** Re-run the full enrichment (used by the `failed` retry button). */
  onRegenerate?: () => void;
} = {}) {
  const t = useTranslations("aiOverview");
  const overview = useTripAiOverview();
  const aiBlockStatus = useUiStore((s) => s.blockStatus.ai);
  const aiConfigured = useUiStore((s) => s.aiCapability.configured);
  const [isMobileExpanded, setIsMobileExpanded] = useState(false);
  const detailsId = useId();

  const paragraphs = overview?.narrative
    ? overview.narrative
        .split(/\n\s*\n/)
        .map((p) => p.trim())
        .filter((p) => p.length > 0)
    : [];

  // AI surfaces are gated by configuration upstream (the `AiUnavailableNotice`
  // in TripPlanner); when no provider is configured this card stays silent.
  if (aiConfigured && paragraphs.length === 0) {
    // Pending / running — show a skeleton over the trip view (ADR-043).
    if (aiBlockStatus === "pending" || aiBlockStatus === "running") {
      return (
        <Card
          data-testid="trip-ai-overview-loading"
          className="border-brand/30 bg-brand/5"
          aria-busy="true"
        >
          <CardContent className="flex items-start gap-3">
            <span
              aria-hidden="true"
              className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand/15 text-brand"
            >
              <Loader2 className="h-4 w-4 animate-spin" />
            </span>
            <div className="flex-1 space-y-2">
              <p className="text-sm text-muted-foreground">{t("loading")}</p>
              <Skeleton className="h-3 w-full" />
              <Skeleton className="h-3 w-4/5" />
            </div>
          </CardContent>
        </Card>
      );
    }

    // Failed — surface the error + a retry affordance (ADR-043).
    if (aiBlockStatus === "failed") {
      return (
        <Card
          data-testid="trip-ai-overview-failed"
          className="border-destructive/40 bg-destructive/5"
        >
          <CardContent className="flex items-center justify-between gap-3">
            <div className="flex items-start gap-3">
              <span
                aria-hidden="true"
                className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-destructive/15 text-destructive"
              >
                <Bot className="h-4 w-4" />
              </span>
              <p className="text-sm text-muted-foreground">{t("failed")}</p>
            </div>
            {onRegenerate && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={onRegenerate}
                className="inline-flex items-center gap-2"
                data-testid="trip-ai-overview-regenerate"
              >
                <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
                {t("regenerate")}
              </Button>
            )}
          </CardContent>
        </Card>
      );
    }
  }

  // Silent fallback when no overview is available (idle / TTL-expired / not
  // configured) — the original #305 contract: render nothing at all.
  if (!overview || paragraphs.length === 0) {
    return null;
  }

  const firstParagraph = paragraphs[0] ?? "";
  const hasMore =
    paragraphs.length > 1 ||
    overview.patterns.length > 0 ||
    overview.recommendations.length > 0 ||
    overview.crossStageAlerts.length > 0;

  return (
    <Card
      data-testid="trip-ai-overview"
      className={cn(
        // Subtle border + muted background to distinguish from the rule-based
        // stage cards rendered below.
        "border-brand/30 bg-brand/5",
      )}
    >
      <CardContent className="flex flex-col gap-4">
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <span
              aria-hidden="true"
              className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand/15 text-brand"
            >
              <Bot className="h-4 w-4" />
            </span>
            <div>
              <h2 className="text-base font-semibold leading-tight">
                {t("title")}
              </h2>
              <p className="text-muted-foreground text-xs">{t("subtitle")}</p>
            </div>
          </div>

          {/* Mobile-only disclosure button — desktop always shows the full
              content thanks to the `md:flex` override below. */}
          {hasMore && (
            <button
              type="button"
              onClick={() => setIsMobileExpanded((v) => !v)}
              aria-expanded={isMobileExpanded}
              aria-controls={detailsId}
              aria-label={
                isMobileExpanded ? t("collapseAria") : t("expandAria")
              }
              className={cn(
                "md:hidden inline-flex items-center gap-1 rounded-md border border-border",
                "px-2 py-1 text-xs text-muted-foreground hover:bg-accent",
                "cursor-pointer",
              )}
              data-testid="trip-ai-overview-toggle"
            >
              {isMobileExpanded ? (
                <>
                  <span>{t("collapse")}</span>
                  <ChevronUp className="h-3 w-3" />
                </>
              ) : (
                <>
                  <span>{t("expand")}</span>
                  <ChevronDown className="h-3 w-3" />
                </>
              )}
            </button>
          )}
        </div>

        {/* Always-visible teaser: first paragraph (truncated to a single line
            on mobile when collapsed, full text otherwise). */}
        <p
          className={cn(
            "text-sm whitespace-pre-line",
            // Collapsed mobile state: clamp to one line. On md+ screens we
            // restore the full paragraph regardless of disclosure state.
            !isMobileExpanded && "line-clamp-1 md:line-clamp-none",
          )}
          data-testid="trip-ai-overview-teaser"
        >
          {firstParagraph}
        </p>

        {/* Expanded content. Mobile: revealed by the disclosure toggle.
            Desktop: always rendered (md:flex) so the user sees everything
            without interaction, per the issue spec. */}
        {hasMore && (
          <div
            id={detailsId}
            data-testid="trip-ai-overview-details"
            className={cn(
              "flex flex-col gap-4 text-sm",
              !isMobileExpanded && "hidden md:flex",
            )}
          >
            {/* Remaining narrative paragraphs (after the teaser). */}
            {paragraphs.length > 1 && (
              <div className="flex flex-col gap-2">
                {paragraphs.slice(1).map((paragraph, idx) => (
                  <p key={idx} className="whitespace-pre-line">
                    {paragraph}
                  </p>
                ))}
              </div>
            )}

            {overview.patterns.length > 0 && (
              <section data-testid="trip-ai-overview-patterns">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  {t("patternsHeading")}
                </h3>
                <ul className="mt-1 list-disc space-y-1 pl-5">
                  {overview.patterns.map((pattern, idx) => (
                    <li key={idx}>{pattern}</li>
                  ))}
                </ul>
              </section>
            )}

            {overview.recommendations.length > 0 && (
              <section data-testid="trip-ai-overview-recommendations">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  {t("recommendationsHeading")}
                </h3>
                <ul className="mt-1 list-disc space-y-1 pl-5">
                  {overview.recommendations.map((rec, idx) => (
                    <li key={idx}>{rec}</li>
                  ))}
                </ul>
              </section>
            )}

            {overview.crossStageAlerts.length > 0 && (
              <section
                data-testid="trip-ai-overview-cross-stage-alerts"
                className="rounded-md border border-amber-500/40 bg-amber-500/5 p-3"
              >
                <h3 className="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                  {t("crossStageAlertsHeading")}
                </h3>
                <ul className="mt-1 list-disc space-y-1 pl-5">
                  {overview.crossStageAlerts.map((alert, idx) => (
                    <li key={idx}>{alert}</li>
                  ))}
                </ul>
              </section>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
