"use client";

import { useId, useState } from "react";
import { useTranslations } from "next-intl";
import { Bot, ChevronDown, ChevronUp } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import { useTripAiOverview } from "@/store/trip-store";

/**
 * Acte 3 — Trip-level AI overview card (issue #305).
 *
 * Renders the narrative + patterns + recommendations + cross-stage alerts
 * produced by the LLaMA pass 2 (`AnalyzeTripOverviewWithLlmHandler` →
 * `aiOverview` field on the `trip_ready` Mercure event).
 *
 * Placement — top of the "Mon voyage" view (Acte 3), before the stage cards.
 *
 * Behavior:
 * - Renders nothing when the store has no overview (LLM disabled / failed /
 *   pending) — silent fallback as required by the issue spec.
 * - On desktop (≥ md) the card is always fully expanded.
 * - On mobile (< md) only the title + first narrative line are visible until
 *   the user taps the disclosure button.
 *
 * The narrative is plain text (no full markdown parser is needed for the
 * current backend output); paragraph breaks (`\n\n`) and line breaks (`\n`)
 * are preserved by splitting on whitespace boundaries and emitting individual
 * `<p>` elements. Bullet lists for patterns, recommendations and alerts are
 * rendered explicitly so screen readers announce them as such.
 */
export function TripAiOverview() {
  const t = useTranslations("aiOverview");
  const overview = useTripAiOverview();
  const [isMobileExpanded, setIsMobileExpanded] = useState(false);
  const detailsId = useId();

  const paragraphs = overview?.narrative
    ? overview.narrative
        .split(/\n\s*\n/)
        .map((p) => p.trim())
        .filter((p) => p.length > 0)
    : [];

  // Silent fallback when no overview is available — the issue spec requires
  // that the component does not render anything at all in that case.
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
